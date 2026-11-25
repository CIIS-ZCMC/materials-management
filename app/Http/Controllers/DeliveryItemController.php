<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDeliveryItemsRequest;
use App\Http\Requests\VoidDeliveryItemRequest;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Services\DeliveryItemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 6.1: the PO Reviewer's delivery update (delivered quantity per PO line and date). The section gate
 * (routes/web.php) opens the pages; DeliveryPolicy decides each action (delivery.update-items + DeliveryItemRule);
 * DeliveryItemService does the work in one transaction. A row is always the one of the delivery in the URL (404 otherwise).
 */
class DeliveryItemController extends Controller
{
    public function __construct(private readonly DeliveryItemService $service) {}

    /** The form: every line of the delivery's PO with its running totals. */
    public function create(Delivery $delivery): Response
    {
        Gate::authorize('updateItems', $delivery);

        $delivery->loadMissing('purchaseOrder.supplier');

        return Inertia::render('deliveries/items/create', [
            'delivery' => [
                'id' => $delivery->id,
                'iar_number' => $delivery->iar_number,
                'date_received' => $delivery->date_received->toDateString(),
                'purchase_order' => [
                    'id' => $delivery->purchaseOrder->id,
                    'po_number' => $delivery->purchaseOrder->po_number,
                    'supplier' => $delivery->purchaseOrder->supplier?->name,
                ],
            ],
            'sheet' => $this->service->sheet($delivery),
        ]);
    }

    public function store(SaveDeliveryItemsRequest $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('updateItems', $delivery);

        $saved = $this->service->record($delivery, (array) $request->validated('lines'), $request->user());
        $count = count($saved);

        return redirect()
            ->to('/deliveries/'.$delivery->id)
            ->with('success', $count === 1 ? 'Delivered item saved.' : "{$count} delivered items saved.");
    }

    public function void(VoidDeliveryItemRequest $request, Delivery $delivery, DeliveryItem $item): RedirectResponse
    {
        if ($item->delivery_id !== $delivery->id) {
            abort(404);
        }

        Gate::authorize('voidItem', [$delivery, $item]);

        $this->service->void($delivery, $item, (string) $request->validated('reason'), $request->user());

        return redirect()
            ->to('/deliveries/'.$delivery->id)
            ->with('success', 'Delivered item voided. The totals were updated.');
    }
}