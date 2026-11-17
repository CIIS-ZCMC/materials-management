<?php

namespace App\Services;

use App\Enums\PoDocumentStatus;
use App\Enums\PoDocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Audit;
use App\Support\DisplayTime;
use App\Support\ImagePdf;
use App\Support\PoDocumentRule;
use App\Support\Qpdf;
use App\Support\StatusTransition;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The documents of a PO (steps 4.2 and 4.2b). This service does not check permissions
 * (the Form Request and the Policy do); it checks the PO status rules (PoDocumentRule)
 * again inside each transaction, with the PO row locked.
 *
 * upload():
 *   1. Check the file itself (real content, qpdf for PDFs, image rules). A bad
 *      file is rejected here and never touches the database or the disk.
 *   2. In ONE database transaction, with the PO row locked: check PoDocumentRule,
 *      write the files to the PRIVATE disk under a random name, create the
 *      po_documents row (next page number) and write the audit entry (log "po").
 *      Since 4.2b an upload NEVER changes the PO status; submit() does.
 *   3. If anything in step 2 fails, the transaction is rolled back and the files
 *      already written are deleted.
 *
 * submit():  draft -> uploaded through StatusTransition::apply() (needs one active document).
 * remove():  a real delete, draft only (row and files); the page numbers close the gap.
 * move():    swaps a document with the next or previous ACTIVE one (draft and escalated).
 * fileResponse(): streams one stored PDF inline through an authorized route.
 *
 * A JPG or PNG becomes a one-page PDF at upload. stored_path is always the PDF
 * that step 4.3 merges; original_path keeps the uploaded image.
 * Errors that belong to a button use the error key "form"; the file box uses "file".
 */
final class PoDocumentService
{
    /** The private disk: storage/app/private, never served directly. */
    public const DISK = 'local';

    /** Images above this many pixels are refused (a small PNG can unpack to a huge image). */
    private const MAX_PIXELS = 36_000_000;

    /** Brands of an HEIC/HEIF file (bytes 8 to 11, after "ftyp"). */
    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    private const MIME = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];

    /** Per-file limit as text for messages and the page, for example "10". */
    public static function maxMegabytes(): string
    {
        $mb = (int) config('mms.uploads.max_size_kb') / 1024;

        return rtrim(rtrim(number_format($mb, 1, '.', ''), '0'), '.');
    }

    /**
     * @throws ValidationException error key "file", shown under the file box
     */
    public function upload(PurchaseOrder $po, UploadedFile $file, PoDocumentType $type, User $actor): PoDocument
    {
        $kind = $this->inspect($file);
        $pdfBytes = $kind === 'pdf' ? null : $this->imageToPdf($file, $kind);

        $disk = Storage::disk(self::DISK);
        $directory = 'po-documents/'.$po->getKey();
        $name = (string) Str::uuid();
        $written = [];

        try {
            return DB::transaction(function () use ($po, $file, $type, $actor, $kind, $pdfBytes, $disk, $directory, $name, &$written): PoDocument {
                $locked = $this->lock($po);

                $this->assertAllowed(PoDocumentRule::addBlockReason($locked), 'file');

                $storedPath = "{$directory}/{$name}.pdf";
                $originalPath = null;
                $written[] = $storedPath;

                if ($pdfBytes === null) {
                    $this->stored($disk->putFileAs($directory, $file, "{$name}.pdf"));
                } else {
                    $originalPath = "{$directory}/{$name}.{$kind}";
                    $written[] = $originalPath;
                    $this->stored($disk->put($storedPath, $pdfBytes));
                    $this->stored($disk->putFileAs($directory, $file, "{$name}.{$kind}"));
                }

                $order = (int) PoDocument::query()->where('purchase_order_id', $locked->getKey())->max('sort_order') + 1;
                $originalName = $this->cleanName($file->getClientOriginalName());

                $document = PoDocument::create([
                    'purchase_order_id' => $locked->getKey(),
                    'type' => $type,
                    'original_name' => $originalName,
                    'stored_path' => $storedPath,
                    'original_path' => $originalPath,
                    'mime' => self::MIME[$kind],
                    'size_bytes' => (int) $file->getSize(),
                    'uploaded_by' => $actor->getKey(),
                    'sort_order' => $order,
                ]);

                Audit::log(
                    event: 'document_uploaded',
                    subject: $locked,
                    description: "Uploaded {$type->label()} ({$originalName}) to PO {$locked->po_number}",
                    new: ['documents' => "{$type->label()}: {$originalName}"],
                    extra: ['document_id' => $document->getKey(), 'sort_order' => $order],
                    causer: $actor,
                    log: 'po',
                );

                return $document;
            });
        } catch (Throwable $e) {
            if ($written !== []) {
                $disk->delete($written);
            }

            throw $e;
        }
    }

    /**
     * "Submit to Reviewer": draft -> uploaded, in one transaction with the PO row locked.
     * The status change is logged by StatusTransition::apply() (log "po", event status_changed).
     *
     * @throws ValidationException error key "form"
     */
    public function submit(PurchaseOrder $po): void
    {
        DB::transaction(function () use ($po): void {
            $locked = $this->lock($po);

            $this->assertAllowed(PoDocumentRule::submitBlockReason($locked), 'form');

            $count = PoDocument::query()
                ->where('purchase_order_id', $locked->getKey())
                ->where('status', PoDocumentStatus::Active->value)
                ->count();

            if ($count === 0) {
                throw ValidationException::withMessages([
                    'form' => 'Upload at least one document before submitting the PO to the PO Reviewer.',
                ]);
            }

            StatusTransition::apply(
                $locked,
                'status',
                PurchaseOrderStatus::Uploaded,
                extra: ['documents' => $count],
                log: 'po',
            );
        });
    }

    /**
     * Deletes a document (row and files). Draft only: later a wrong document is voided with a
     * reason (step 4.3). The page numbers after it move up by one, so there is no gap.
     * The files are deleted after the commit, so a rollback never loses a file.
     *
     * @throws ValidationException error key "form"
     */
    public function remove(PurchaseOrder $po, PoDocument $document, User $actor): void
    {
        $paths = DB::transaction(function () use ($po, $document, $actor): array {
            $locked = $this->lock($po);

            $this->assertAllowed(PoDocumentRule::removeBlockReason($locked), 'form');

            $target = PoDocument::query()
                ->whereKey($document->getKey())
                ->where('purchase_order_id', $locked->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $position = $target->sort_order;
            $summary = "{$target->type->label()}: {$target->original_name}";
            $paths = array_values(array_filter([$target->stored_path, $target->original_path]));

            $target->delete();

            // Close the gap one row at a time, lowest first: each row moves into the number just freed.
            PoDocument::query()
                ->where('purchase_order_id', $locked->getKey())
                ->where('sort_order', '>', $position)
                ->orderBy('sort_order')
                ->get()
                ->each(function (PoDocument $next): void {
                    $next->sort_order = $next->sort_order - 1;
                    $next->save();
                });

            Audit::log(
                event: 'document_removed',
                subject: $locked,
                description: "Removed {$summary} from PO {$locked->po_number}",
                old: ['documents' => $summary],
                extra: ['document_id' => $document->getKey(), 'sort_order' => $position],
                causer: $actor,
                log: 'po',
            );

            return $paths;
        });

        Storage::disk(self::DISK)->delete($paths);
    }

    /**
     * Moves a document one place up or down among the ACTIVE documents (a voided one keeps its
     * number and is skipped). The two numbers are swapped through a temporary number because
     * (purchase_order_id, sort_order) is unique.
     *
     * @param  'up'|'down'  $direction
     *
     * @throws ValidationException error key "form"
     */
    public function move(PurchaseOrder $po, PoDocument $document, string $direction, User $actor): void
    {
        DB::transaction(function () use ($po, $document, $direction, $actor): void {
            $locked = $this->lock($po);

            $this->assertAllowed(PoDocumentRule::reorderBlockReason($locked), 'form');

            $active = PoDocument::query()
                ->where('purchase_order_id', $locked->getKey())
                ->where('status', PoDocumentStatus::Active->value)
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get()
                ->values();

            $index = $active->search(fn (PoDocument $row) => $row->getKey() === $document->getKey());

            if ($index === false) {
                throw ValidationException::withMessages(['form' => 'That document is not an active document of this PO.']);
            }

            $neighbourIndex = $direction === 'up' ? $index - 1 : $index + 1;
            $neighbour = $neighbourIndex < 0 ? null : $active->get($neighbourIndex);

            if ($neighbour === null) {
                throw ValidationException::withMessages([
                    'form' => $direction === 'up' ? 'This document is already the first one.' : 'This document is already the last one.',
                ]);
            }

            /** @var PoDocument $moving */
            $moving = $active->get($index);
            $movingNumber = $moving->sort_order;
            $neighbourNumber = $neighbour->sort_order;
            $temporary = (int) PoDocument::query()->where('purchase_order_id', $locked->getKey())->max('sort_order') + 1;

            $moving->sort_order = $temporary;
            $moving->save();
            $neighbour->sort_order = $movingNumber;
            $neighbour->save();
            $moving->sort_order = $neighbourNumber;
            $moving->save();

            Audit::log(
                event: 'documents_reordered',
                subject: $locked,
                description: "Moved {$moving->original_name} {$direction} in PO {$locked->po_number} (place ".($index + 1).' to '.($neighbourIndex + 1).')',
                extra: ['document_id' => $moving->getKey(), 'direction' => $direction],
                causer: $actor,
                log: 'po',
            );
        });
    }

    /** True when the PO has at least one active document (drives the Submit button). */
    public function hasActive(PurchaseOrder $po): bool
    {
        return PoDocument::query()
            ->where('purchase_order_id', $po->getKey())
            ->where('status', PoDocumentStatus::Active->value)
            ->exists();
    }

    /**
     * Streams one stored PDF inline (an image upload is already a PDF here). The caller has
     * already checked the permission and that the document belongs to the PO.
     */
    public function fileResponse(PoDocument $document): StreamedResponse
    {
        $disk = Storage::disk(self::DISK);

        abort_unless($disk->exists($document->stored_path), 404);

        $base = pathinfo($document->original_name, PATHINFO_FILENAME);

        return $disk->response($document->stored_path, ($base !== '' ? $base : 'document').'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Rows for the Documents tab, in page order (voided ones included).
     *
     * @return list<array<string, mixed>>
     */
    public function rowsFor(PurchaseOrder $po): array
    {
        return PoDocument::query()
            ->where('purchase_order_id', $po->getKey())
            ->with('uploader:id,name')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (PoDocument $document) => [
                'id' => $document->id,
                'sort_order' => $document->sort_order,
                'type' => $document->type->label(),
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'status' => $document->status->value,
                'uploaded_by' => $document->uploader?->name,
                'uploaded_at' => $document->created_at ? DisplayTime::format($document->created_at) : null,
            ])
            ->values()
            ->all();
    }

    /** The PO row, locked for the rest of the transaction. */
    private function lock(PurchaseOrder $po): PurchaseOrder
    {
        return PurchaseOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @throws ValidationException */
    private function assertAllowed(?string $reason, string $key): void
    {
        if ($reason !== null) {
            throw ValidationException::withMessages([$key => $reason]);
        }
    }

    /**
     * Decides what the file really is from its CONTENT (not its name) and, for a
     * PDF, asks qpdf whether it can be read.
     *
     * @return 'pdf'|'jpg'|'png'
     *
     * @throws ValidationException
     */
    private function inspect(UploadedFile $file): string
    {
        $path = (string) $file->getRealPath();
        $head = (string) @file_get_contents($path, false, null, 0, 1024);
        $extension = strtolower($file->getClientOriginalExtension());
        $allowed = array_map('strtolower', (array) config('mms.uploads.allowed_extensions'));

        if ($this->looksLikeHeic($head) || in_array($extension, ['heic', 'heif'], true)) {
            throw $this->fail('iPhone HEIC photos cannot be uploaded. Save or send the photo as JPG (iPhone: Settings > Camera > Formats > Most Compatible), then upload it again.');
        }

        if (! in_array($extension, $allowed, true)) {
            throw $this->typeError($allowed);
        }

        if (str_contains($head, '%PDF-')) {
            $kind = 'pdf';
        } elseif (str_starts_with($head, "\xFF\xD8\xFF")) {
            $kind = 'jpg';
        } elseif (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            $kind = 'png';
        } else {
            throw $this->typeError($allowed);
        }

        $kindExtensions = ['pdf' => ['pdf'], 'jpg' => ['jpg', 'jpeg'], 'png' => ['png']][$kind];

        if (array_intersect($kindExtensions, $allowed) === []) {
            throw $this->typeError($allowed);
        }

        if ($kind === 'pdf') {
            $this->checkPdf($path);
        }

        return $kind;
    }

    /** @throws ValidationException */
    private function checkPdf(string $path): void
    {
        try {
            $exit = Qpdf::check($path);
        } catch (Throwable $e) {
            report($e);

            throw $this->unavailable();
        }

        // 0 = fine, 3 = fine with warnings (real office files give it), 2 = damaged or password-protected.
        if ($exit === 0 || $exit === 3) {
            return;
        }

        if ($exit === 2) {
            throw $this->fail('The file is damaged or password-protected. Rescan it or remove the password.');
        }

        report(new RuntimeException("qpdf --check returned exit code {$exit}."));

        throw $this->unavailable();
    }

    /**
     * @param  'jpg'|'png'  $kind
     *
     * @throws ValidationException
     */
    private function imageToPdf(UploadedFile $file, string $kind): string
    {
        $path = (string) $file->getRealPath();
        $info = @getimagesize($path);
        $expected = $kind === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        if ($info === false || $info[2] !== $expected) {
            throw $this->fail('This image could not be read. Save it again as a normal JPG or PNG, then upload it again.');
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw $this->fail('This image is too large (too many pixels). Reduce its size or take the photo at a lower setting, then upload it again.');
        }

        if ($kind === 'png' && ImagePdf::isInterlacedPng($path)) {
            throw $this->fail('This PNG is interlaced and cannot be used. Save it again as a normal PNG or as a JPG, then upload it again.');
        }

        try {
            return ImagePdf::fromFile($path, $kind, $info[0], $info[1]);
        } catch (Exception) {
            throw $this->fail('This image could not be turned into a PDF page. Save it again as a normal JPG or PNG, then upload it again.');
        }
    }

    private function looksLikeHeic(string $head): bool
    {
        return substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIC_BRANDS, true);
    }

    /** @param list<string> $allowed */
    private function typeError(array $allowed): ValidationException
    {
        $names = array_values(array_unique(array_map('strtoupper', $allowed)));
        $last = array_pop($names);
        $list = $names === [] ? (string) $last : implode(', ', $names).' and '.$last;

        return $this->fail("Only {$list} files can be uploaded. Check that the file is a real PDF, JPG or PNG.");
    }

    private function unavailable(): ValidationException
    {
        return $this->fail('The PDF could not be checked because the PDF tool (qpdf) is not working on the server. Please tell IT.');
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }

    /** A failed disk write returns false (the disk is set not to throw). */
    private function stored(string|bool $result): void
    {
        if ($result === false) {
            throw new RuntimeException('The file could not be saved on the server.');
        }
    }

    /** The name is only shown on screen, never used as a path. Strips folders and control characters. */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return mb_substr(trim($name) !== '' ? trim($name) : 'file', 0, 255);
    }
}