<?php

namespace App\Http\Controllers;

use App\Enums\PoDocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\UploadPoDocumentRequest;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Services\PoDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Documents tab of a PO (steps 4.2 and 4.2b). Every action asks PurchaseOrderPolicy
 * (permission + PoDocumentRule) and the Service checks the status rule again under a row lock.
 */
class PoDocumentController extends Controller
{
    public function __construct(private readonly PoDocumentService $documents) {}

    /**
     * Add a file to the PO Bundle. The Form Request asks the Policy (po.upload-files and the PO
     * status, 403); the Service checks the file and does everything in one transaction.
     * The PO status does NOT change here: "Submit to Reviewer" does that (submit()).
     */
    public function store(UploadPoDocumentRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->documents->upload(
            $purchaseOrder,
            $request->file('file'),
            PoDocumentType::PoBundle,
            $request->user(),
        );

        $message = "File added to the PO Bundle of PO {$purchaseOrder->po_number}.";

        if ($purchaseOrder->status === PurchaseOrderStatus::Draft) {
            $message .= ' The PO is still a draft: check the files, then click "Submit to Reviewer".';
        }

        return $this->back($purchaseOrder, $message);
    }

    /** "Submit to Reviewer": draft -> uploaded (needs at least one active document). */
    public function submit(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        Gate::authorize('submitDocuments', $purchaseOrder);

        $this->documents->submit($purchaseOrder);

        return $this->back(
            $purchaseOrder,
            "PO {$purchaseOrder->po_number} was submitted. It is now Uploaded and waits for the PO Reviewer.",
        );
    }

    /** Remove (delete) a file. Draft only. */
    public function destroy(Request $request, PurchaseOrder $purchaseOrder, PoDocument $document): RedirectResponse
    {
        $this->ensureBelongs($purchaseOrder, $document);
        Gate::authorize('removeDocument', $purchaseOrder);

        $this->documents->remove($purchaseOrder, $document, $request->user());

        return $this->back($purchaseOrder, 'The file was removed.');
    }

    /** Move a file one place up or down. Draft and escalated. */
    public function move(Request $request, PurchaseOrder $purchaseOrder, PoDocument $document): RedirectResponse
    {
        $this->ensureBelongs($purchaseOrder, $document);
        Gate::authorize('reorderDocuments', $purchaseOrder);

        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        $this->documents->move($purchaseOrder, $document, $direction, $request->user());

        return $this->back($purchaseOrder, 'The order of the files was changed.');
    }

    /** The View button: streams one stored PDF inline. No public URL; this route is the only way in. */
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

    private function back(PurchaseOrder $purchaseOrder, string $message): RedirectResponse
    {
        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', $message);
    }
}