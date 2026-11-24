<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveDeliveryRequest;
use App\Http\Requests\VoidDeliveryRequest;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Policies\DeliveryPolicy;
use App\Services\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 5.2: delivery batches (one IAR each). The section gate (routes/web.php) opens the pages;
 * DeliveryPolicy decides each action (delivery.encode + DeliveryRule); DeliveryService does the work.
 */
class DeliveryController extends Controller
{
    public function __construct(private readonly DeliveryService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Delivery::class);

        $filters = $this->service->cleanFilters($request->query());
        $page = $this->service->paginate($filters);

        return Inertia::render('deliveries/index', [
            'deliveries' => [
                'data' => $page->items(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'prev_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
            ],
            'filters' => $filters,
        ]);
    }

    /** The form needs the PO (?purchase_order=ID): a delivery always belongs to one. */
    public function create(Request $request): Response|RedirectResponse
    {
        $id = $request->integer('purchase_order');

        if ($id < 1) {
            return redirect('/purchase-orders');
        }

        $po = PurchaseOrder::query()->with('supplier')->findOrFail($id);

        Gate::authorize('create', [Delivery::class, $po]);

        return Inertia::render('deliveries/create', [
            'purchaseOrder' => $this->poSummary($po),
            'options' => $this->service->formOptions(),
        ]);
    }

    /** JSON for the form: the next free IAR number of a series, for the year of the date received. */
    public function iarSuggestion(Request $request): JsonResponse
    {
        Gate::authorize('suggestIar', Delivery::class);

        return response()->json($this->service->suggestIar(
            (string) $request->query('series', ''),
            (string) $request->query('date', ''),
        ));
    }

    public function store(SaveDeliveryRequest $request): RedirectResponse
    {
        $po = PurchaseOrder::query()->findOrFail($request->integer('purchase_order_id'));

        Gate::authorize('create', [Delivery::class, $po]);

        $delivery = $this->service->create($po, $request->validated());

        return redirect()
            ->to('/deliveries/'.$delivery->id)
            ->with('success', "Delivery recorded. IAR number {$delivery->iar_number}.");
    }

    public function show(Delivery $delivery): Response
    {
        Gate::authorize('view', $delivery);

        $delivery->loadMissing('purchaseOrder');

        $update = Gate::inspect('update', $delivery);
        $void = Gate::inspect('void', $delivery);
        $canEncode = (bool) request()->user()?->can(DeliveryPolicy::ENCODE);

        return Inertia::render('deliveries/show', [
            'delivery' => $this->service->detail($delivery),
            'can' => [
                'edit' => $update->allowed(),
                'void' => $void->allowed(),
                // Only people who could act are told why they cannot.
                'edit_reason' => ! $update->allowed() && $canEncode ? $update->message() : null,
                'void_reason' => ! $void->allowed() && $canEncode ? $void->message() : null,
            ],
        ]);
    }

    public function edit(Delivery $delivery): Response
    {
        Gate::authorize('update', $delivery);

        $delivery->loadMissing('purchaseOrder.supplier');

        return Inertia::render('deliveries/edit', [
            'delivery' => $this->service->detail($delivery),
            'purchaseOrder' => $this->poSummary($delivery->purchaseOrder),
            'options' => $this->service->formOptions(),
        ]);
    }

    public function update(SaveDeliveryRequest $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('update', $delivery);

        $this->service->update($delivery, $request->validated());

        return redirect()
            ->to('/deliveries/'.$delivery->id)
            ->with('success', "Delivery {$delivery->iar_number} saved.");
    }

    public function voidDelivery(VoidDeliveryRequest $request, Delivery $delivery): RedirectResponse
    {
        Gate::authorize('void', $delivery);

        $this->service->void($delivery, (string) $request->validated('reason'));

        return redirect()
            ->to('/deliveries/'.$delivery->id)
            ->with('success', "Delivery voided. IAR number {$delivery->iar_number} stays recorded and cannot be used again.");
    }

    /**
     * @return array{id: int, po_number: string, supplier: string|null, po_date: string|null}
     */
    private function poSummary(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier' => $po->supplier?->name,
            'po_date' => $po->po_date?->toDateString(),
        ];
    }
}
