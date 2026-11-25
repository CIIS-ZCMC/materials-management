<?php

namespace App\Services;

use App\Data\ConsolidatedPdfResult;
use App\Enums\PoDocumentStatus;
use App\Exceptions\ConsolidatedPdfFailed;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Support\DisplayTime;
use App\Support\PoDocumentOrder;
use App\Support\Qpdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The ONE consolidated PDF of a PO (step 4.3, docs/PDF-MERGE.md).
 *
 * rebuild() joins the ACTIVE documents with qpdf in page order: the PO's own files by sort_order, then each
 * delivery's files (App\Support\PoDocumentOrder, step 5.3). qpdf is called exactly as before.
 * The caller runs it after the change has been committed (upload, replace, void, remove, move),
 * so a failed merge never undoes the change itself.
 *
 *   1. Lock the PO row (two rebuilds of the same PO never run side by side).
 *   2. Make sure every active document has its page_count (the page index is built from it).
 *   3. Run qpdf into a TEMPORARY file next to the final one.
 *   4. Exit 0 or 3 = success (3 is logged as a warning); the page count of the result must equal
 *      the sum of the parts. Anything else (exit 2, a missing file, a wrong count) = failure.
 *   5. Success: move the temporary file to a new unique name, point purchase_orders.consolidated_path
 *      at it, and delete the OLD file after the commit. Failure: delete the temporary file, keep
 *      the pointer (and so the previous consolidated PDF), set consolidated_stale and a plain
 *      message. A viewer is therefore never pointed at a half-written file.
 *
 * Nothing is resized or rotated. Files are on the private disk and only leave through response(),
 * which the controller guards with PurchaseOrderPolicy::viewConsolidated.
 *
 * QUEUE: rebuild() is synchronous (decided for step 4.3: a merge of a few dozen scanned pages takes
 * well under a second, see docs/PDF-MERGE.md section 3.3). To move it to a queue later, change
 * PoDocumentController::afterChange() to dispatch a job that calls rebuild($po); nothing else
 * changes, because the page already shows "stale" until the new file is in place.
 */
final class PoConsolidatedPdfService
{
    public const DISK = PoDocumentService::DISK;

    public function rebuild(PurchaseOrder $po): ConsolidatedPdfResult
    {
        $disk = Storage::disk(self::DISK);
        $directory = 'po-documents/'.$po->getKey();
        $temporary = "{$directory}/consolidated-".Str::uuid().'.tmp';
        $final = null;
        $old = null;

        try {
            $result = DB::transaction(function () use ($po, $disk, $directory, $temporary, &$final, &$old): ConsolidatedPdfResult {
                $locked = PurchaseOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();
                $old = $locked->consolidated_path;

                // Step 5.3: the PO's own files first, then each delivery's files (PoDocumentOrder). Delivery files
                // of a voided delivery, or (mode when_complete) of an unfinished one, are left out.
                $documents = PoDocumentOrder::sort(
                    PoDocument::query()
                        ->where('purchase_order_id', $locked->getKey())
                        ->where('status', PoDocumentStatus::Active->value)
                        ->with('delivery:id,status')
                        ->orderBy('sort_order')
                        ->lockForUpdate()
                        ->get()
                        ->filter(fn (PoDocument $document) => PoDocumentOrder::included($document))
                );

                if ($documents->isEmpty()) {
                    $this->savePointer($locked, null, null);

                    return ConsolidatedPdfResult::empty();
                }

                $files = [];
                $expected = 0;

                foreach ($documents as $document) {
                    if (! $disk->exists($document->stored_path)) {
                        throw new ConsolidatedPdfFailed("The stored file of \"{$document->original_name}\" is missing on the server.");
                    }

                    $path = $disk->path($document->stored_path);

                    if ($document->page_count === null) {
                        $count = Qpdf::pageCount($path);

                        if ($count < 1) {
                            throw new ConsolidatedPdfFailed("The file \"{$document->original_name}\" could not be read.");
                        }

                        $document->page_count = $count;
                        $document->save();
                    }

                    $files[] = $path;
                    $expected += $document->page_count;
                }

                $disk->makeDirectory($directory);
                $merge = Qpdf::merge($files, $disk->path($temporary));

                if (! in_array($merge['exit'], [0, 3], true)) {
                    Log::error('qpdf could not merge the documents of a PO.', [
                        'po_id' => $locked->getKey(),
                        'exit' => $merge['exit'],
                        'qpdf' => $merge['error'],
                    ]);

                    throw new ConsolidatedPdfFailed('The PDF tool could not combine the files. One of them may be damaged.');
                }

                if ($merge['exit'] === 3) {
                    Log::warning('qpdf merged the documents of a PO with warnings (the result is usable).', [
                        'po_id' => $locked->getKey(),
                        'qpdf' => $merge['error'],
                    ]);
                }

                if (! $disk->exists($temporary) || $disk->size($temporary) === 0) {
                    throw new ConsolidatedPdfFailed('The PDF tool did not produce a file.');
                }

                if (Qpdf::pageCount($disk->path($temporary)) !== $expected) {
                    throw new ConsolidatedPdfFailed('The combined PDF does not have the expected number of pages.');
                }

                $final = "{$directory}/consolidated-".Str::uuid().'.pdf';

                if (! $disk->move($temporary, $final)) {
                    throw new ConsolidatedPdfFailed('The combined PDF could not be saved on the server.');
                }

                $this->savePointer($locked, $final, $expected);

                return ConsolidatedPdfResult::built($expected, $merge['exit'] === 3);
            });
        } catch (Throwable $e) {
            $disk->delete(array_values(array_filter([$temporary, $final])));

            if ($e instanceof ConsolidatedPdfFailed) {
                $message = $e->getMessage();
            } else {
                report($e);
                $message = 'The PDF tool (qpdf) is not working on the server.';
            }

            $this->markStale($po, $message);

            return ConsolidatedPdfResult::failed($message);
        }

        // Only now, with the new pointer committed, is the previous file no longer needed.
        if ($old !== null && $old !== $final) {
            $disk->delete($old);
        }

        return $result;
    }

    /**
     * Streams the consolidated PDF. The caller has already checked the permission.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 404 when none has been built
     */
    public function response(PurchaseOrder $po, bool $download): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        $path = $po->consolidated_path;

        abort_if($path === null || ! $disk->exists($path), 404);

        $name = 'PO-'.trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $po->po_number), '-').'.pdf';

        return $disk->response($path, $name, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $download ? 'attachment' : 'inline');
    }

    /**
     * What the Documents tab needs to know about the consolidated PDF.
     *
     * @return array{exists: bool, pages: int|null, built_at: string|null, stale: bool, error: string|null}
     */
    public function info(PurchaseOrder $po): array
    {
        return [
            'exists' => $po->consolidated_path !== null,
            'pages' => $po->consolidated_pages,
            'built_at' => $po->consolidated_built_at ? DisplayTime::format($po->consolidated_built_at) : null,
            'stale' => (bool) $po->consolidated_stale,
            'error' => $po->consolidated_stale ? $po->consolidated_error : null,
        ];
    }

    /** Points the PO at the new file (or at nothing) and clears the "stale" state. */
    private function savePointer(PurchaseOrder $locked, ?string $path, ?int $pages): void
    {
        PurchaseOrder::query()->whereKey($locked->getKey())->update([
            'consolidated_path' => $path,
            'consolidated_pages' => $pages,
            'consolidated_built_at' => $path === null ? null : now(),
            'consolidated_stale' => false,
            'consolidated_error' => null,
        ]);
    }

    /** The rebuild failed: keep the old file, but tell the page it is out of date. */
    private function markStale(PurchaseOrder $po, string $message): void
    {
        try {
            PurchaseOrder::query()->whereKey($po->getKey())->update([
                'consolidated_stale' => true,
                'consolidated_error' => mb_substr($message, 0, 500),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}