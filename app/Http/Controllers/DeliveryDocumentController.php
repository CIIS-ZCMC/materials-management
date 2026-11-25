<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReopenDeliveryRequest;
use App\Http\Requests\ReplaceDeliveryDocumentRequest;
use App\Http\Requests\UploadDeliveryDocumentRequest;
use App\Http\Requests\VoidDeliveryDocumentRequest;
use App\Models\Delivery;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Services\DeliveryDocumentService;
use App\Services\PoConsolidatedPdfService;
use App\Services\PoDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The documents of one delivery (step 5.3). The Form Requests ask DeliveryPolicy (permission + DeliveryDocumentRule);
 * DeliveryDocumentService / PoDocumentService check the rule again under row locks.
 *
 * After EVERY change the controller rebuilds the PO's combined PDF (afterChange), exactly as PoDocumentController
 * does: the change is already committed, a failed rebuild keeps the previous PDF and says so in the message.
 * A rebuild also follows "Mark complete" and "Reopen", because with delivery_in_pdf = when_complete the status
 * decides whether the files are in the PDF.
 */
class DeliveryDocumentController extends Controller
{
    public function __construct(
        private readonly DeliveryDocumentService $service,
        private readonly PoDocumentService $documents,
        private readonly PoConsolidatedPdfService $pdf,
    ) {}

    public function store(UploadDeliveryDocumentRequest $request, Delivery $delivery): RedirectResponse
    {
        $groups = collect((array) $request->validated('groups'))
            ->map(fn (array $group) => [
                'type' => (string) $group['type'],
                'files' => array_values((array) $group['files']),
            ])
            ->values()
            ->all();

        $created = $this->service->upload($delivery, $groups, $request->user());

        $count = count($created);
        $types = collect($created)->map(fn (PoDocument $document) => $document->type->label())->unique()->values()->all();

        return $this->afterChange($delivery, ($count === 1 ? '1 file was' : "{$count} files were")." added to delivery {$delivery->iar_number} (".implode(', ', $types).').');
    }

    public function void(VoidDeliveryDocumentRequest $request, Delivery $delivery, PoDocument $document): RedirectResponse
    {
        $this->ensureBelongs($delivery, $document);

        $result = $this->service->void($delivery, $document, (string) $request->validated('reason'), $request->user());

        $message = 'The file was voided. Its pages are no longer in the combined PDF.';

        if ($result['reopened']) {
            $message .= ' A required file is now missing, so the delivery went back to "For uploading".';
        }

        return $this->afterChange($delivery, $message);
    }

    public function replace(ReplaceDeliveryDocumentRequest $request, Delivery $delivery, PoDocument $document): RedirectResponse
    {
        $this->ensureBelongs($delivery, $document);

        $this->service->replace($delivery, $document, $request->file('file'), (string) $request->validated('reason'), $request->user());

        return $this->afterChange($delivery, 'The file was replaced. The new file is in the same place in the combined PDF.');
    }

    /** "Mark complete": allowed only when every required file exists (the message names the missing ones). */
    public function complete(Request $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('completeDocuments', $delivery);

        $this->service->complete($delivery, $request->user());

        return $this->afterChange($delivery, "Delivery {$delivery->iar_number} is now \"Documents complete\".");
    }

    /** "Reopen for uploading": complete -> for_uploading, with a reason. */
    public function reopen(ReopenDeliveryRequest $request, Delivery $delivery): RedirectResponse
    {
        $this->service->reopen($delivery, (string) $request->validated('reason'), $request->user());

        return $this->afterChange($delivery, "Delivery {$delivery->iar_number} was reopened for uploading.");
    }

    /** Opens ONE stored file inline. No public URL; this route (and the PO file route) are the only ways in. */
    public function file(Delivery $delivery, PoDocument $document): StreamedResponse
    {
        $this->ensureBelongs($delivery, $document);
        Gate::authorize('viewDocument', $delivery);

        return $this->documents->fileResponse($document);
    }

    /** A file of another delivery (or of the PO itself) is "not found", whatever the person's permissions. */
    private function ensureBelongs(Delivery $delivery, PoDocument $document): void
    {
        abort_unless($document->delivery_id === $delivery->id && $document->purchase_order_id === $delivery->purchase_order_id, 404);
    }

    private function afterChange(Delivery $delivery, string $message): RedirectResponse
    {
        $result = $this->pdf->rebuild(PurchaseOrder::query()->findOrFail($delivery->purchase_order_id));

        if (! $result->ok) {
            $message .= ' Your change was saved, but the combined PDF could not be rebuilt ('.rtrim((string) $result->message, '.').'). '
                .'The PO page still shows the previous version. Use "Rebuild combined PDF" on the PO to try again, or tell IT if it keeps failing.';
        }

        return redirect()->to('/deliveries/'.$delivery->id)->with('success', $message);
    }
}
