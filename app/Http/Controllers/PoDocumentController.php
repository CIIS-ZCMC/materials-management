<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\ReplacePoDocumentRequest;
use App\Http\Requests\UploadPoDocumentRequest;
use App\Http\Requests\VoidPoDocumentRequest;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Services\PoConsolidatedPdfService;
use App\Services\PoDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Documents tab of a PO (steps 4.2, 4.2b, 4.2c and 4.3). Every action asks PurchaseOrderPolicy
 * (permission + PoDocumentRule) and the Service checks the status rule again under a row lock.
 *
 * Step 4.3: after every change to the documents (upload, replace, void, remove, move) the
 * controller rebuilds the consolidated PDF with afterChange(). The change is already committed
 * by then; if the rebuild fails the previous consolidated PDF stays and the message says so.
 */
class PoDocumentController extends Controller
{
    public function __construct(
        private readonly PoDocumentService $documents,
        private readonly PoConsolidatedPdfService $pdf,
    ) {}

    /**
     * Add files, each group with its document type (step 4.2c). The Form Request asks the Policy
     * (po.upload-files and the PO status, 403) and checks the shape; the Service checks every file first
     * and then saves ALL of them in one transaction (all or nothing). The combined PDF is rebuilt ONCE,
     * after the whole request, not once per file. The PO status does NOT change here: "Submit to Reviewer"
     * does that (submit()).
     */
    public function store(UploadPoDocumentRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $groups = collect((array) $request->validated('groups'))
            ->map(fn (array $group) => [
                'type' => (string) $group['type'],
                'files' => array_values((array) $group['files']),
            ])
            ->values()
            ->all();

        $created = $this->documents->uploadMany($purchaseOrder, $groups, $request->user());

        $count = count($created);
        $types = collect($created)->map(fn (PoDocument $document) => $document->type->label())->unique()->values()->all();
        $message = ($count === 1 ? '1 file was' : "{$count} files were")." added to PO {$purchaseOrder->po_number} (".implode(', ', $types).').';

        if ($purchaseOrder->status === PurchaseOrderStatus::Draft) {
            $message .= ' The PO is still a draft: check the combined PDF, then click "Submit to Reviewer".';
        }

        return $this->afterChange($purchaseOrder, $message);
    }

    /**
     * "Submit to Reviewer": draft -> uploaded (needs at least one active document).
     * The Reviewer must find a combined PDF, so one is built here if it is missing or out of date.
     */
    public function submit(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('submitDocuments', $purchaseOrder);

        $this->documents->submit($purchaseOrder);

        $message = "PO {$purchaseOrder->po_number} was submitted. It is now Uploaded and waits for the PO Reviewer.";
        $fresh = $purchaseOrder->fresh();

        if ($fresh === null || $fresh->consolidated_path === null || $fresh->consolidated_stale) {
            return $this->afterChange($purchaseOrder, $message);
        }

        return $this->back($purchaseOrder, $message);
    }

    /** Remove (delete) a file. Draft only. */
    public function destroy(Request $request, PurchaseOrder $purchaseOrder, PoDocument $document): RedirectResponse
    {
        $this->ensureBelongs($purchaseOrder, $document);
        Gate::authorize('removeDocument', $purchaseOrder);

        $this->documents->remove($purchaseOrder, $document, $request->user());

        return $this->afterChange($purchaseOrder, 'The file was removed.');
    }

    /** Move a file one place up or down. Who may, and at which PO status: PoDocumentRule::REORDER (step 4.2d). */
    public function move(Request $request, PurchaseOrder $purchaseOrder, PoDocument $document): RedirectResponse
    {
        $this->ensureBelongs($purchaseOrder, $document);
        Gate::authorize('reorderDocuments', $purchaseOrder);

        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        $this->documents->move($purchaseOrder, $document, $direction, $request->user());

        return $this->afterChange($purchaseOrder, 'The order of the files was changed.');
    }

    /** Void a wrong document with a reason (uploaded and escalated POs). Its pages leave the combined PDF. */
    public function void(VoidPoDocumentRequest $request, PurchaseOrder $purchaseOrder, PoDocument $document): RedirectResponse
    {
        $this->ensureBelongs($purchaseOrder, $document);

        $this->documents->void($purchaseOrder, $document, (string) $request->validated('reason'), $request->user());

        return $this->afterChange($purchaseOrder, 'The document was voided. Its pages are no longer in the combined PDF.');
    }

    /** Replace a wrong document: void it with a reason and put the new file in its place. */
    public function replace(ReplacePoDocumentRequest $request, PurchaseOrder $purchaseOrder, PoDocument $document): RedirectResponse
    {
        $this->ensureBelongs($purchaseOrder, $document);

        $this->documents->replace(
            $purchaseOrder,
            $document,
            $request->file('file'),
            (string) $request->validated('reason'),
            $request->user(),
        );

        return $this->afterChange($purchaseOrder, 'The document was replaced. The new file is in the same place in the combined PDF.');
    }

    /** "Rebuild combined PDF": a retry after a failed rebuild. Repeatable and harmless, at any PO status. */
    public function rebuild(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('rebuildConsolidated', $purchaseOrder);

        return $this->afterChange($purchaseOrder, 'The combined PDF was rebuilt.');
    }

    /** The consolidated PDF: inline for the viewer, or as a download with ?download=1. No public URL. */
    public function consolidated(Request $request, PurchaseOrder $purchaseOrder): StreamedResponse
    {
        Gate::authorize('viewConsolidated', $purchaseOrder);

        return $this->pdf->response($purchaseOrder, $request->boolean('download'));
    }

    /** The View ("Original") button: streams one stored PDF inline. No public URL; this route is the only way in. */
    public function file(PurchaseOrder $purchaseOrder, PoDocument $document): StreamedResponse
    {
        $this->ensureBelongs($purchaseOrder, $document);
        Gate::authorize('viewDocument', $purchaseOrder);

        return $this->documents->fileResponse($document);
    }

    /** A document of another PO is "not found", whatever the person's permissions. */
    private function ensureBelongs(PurchaseOrder $purchaseOrder, PoDocument $document): void
    {
        abort_unless($document->purchase_order_id === $purchaseOrder->id, 404);
    }

    /**
     * The change is saved. Rebuild the consolidated PDF now (synchronous; a queue could take over here)
     * and add a plain sentence to the message when the rebuild did not work.
     */
    private function afterChange(PurchaseOrder $purchaseOrder, string $message): RedirectResponse
    {
        $result = $this->pdf->rebuild($purchaseOrder);

        if (! $result->ok) {
            $message .= ' Your change was saved, but the combined PDF could not be rebuilt ('.rtrim((string) $result->message, '.').'). '
                .'The page still shows the previous version. Use "Rebuild combined PDF" to try again, or tell IT if it keeps failing.';
        }

        return $this->back($purchaseOrder, $message);
    }

    private function back(PurchaseOrder $purchaseOrder, string $message): RedirectResponse
    {
        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', $message);
    }
}