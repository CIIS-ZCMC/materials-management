<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\SavePurchaseOrderRequest;
use App\Models\PoExtension;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Support\DisplayTime;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $filters = $this->service->cleanFilters($request->query());
        $paginator = $this->service->paginate($filters);

        return Inertia::render('purchase-orders/index', [
            'purchaseOrders' => [
                'data' => $paginator->getCollection()
                    ->map(fn (PurchaseOrder $po) => $this->row($po))
                    ->values(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
            'filters' => $filters,
            'statusOptions' => collect(PurchaseOrderStatus::cases())
                ->map(fn (PurchaseOrderStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                ->values()
                ->all(),
            // A closure: only loaded on the first visit, not on every keystroke (partial reloads skip it).
            'supplierOptions' => fn () => $this->service->supplierOptions(),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('view', $purchaseOrder);

        return Inertia::render('purchase-orders/show', [
            'purchaseOrder' => $this->detail($this->service->detail($purchaseOrder)),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PurchaseOrder::class);

        return Inertia::render('purchase-orders/create', [
            'supplierOptions' => $this->service->supplierChoices(),
            'dueDateRules' => $this->dueDateRules(),
        ]);
    }

    public function store(SavePurchaseOrderRequest $request): RedirectResponse
    {
        $po = $this->service->create($request->validated());

        return $this->saved($po, "Purchase order {$po->po_number} created.");
    }

    public function edit(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('update', $purchaseOrder);

        $po = $this->service->loadForEdit($purchaseOrder);

        return Inertia::render('purchase-orders/edit', [
            'purchaseOrder' => $this->formData($po),
            'supplierOptions' => $this->service->supplierChoices($po->supplier_id),
            'dueDateRules' => $this->dueDateRules(),
        ]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $po = $this->service->update($purchaseOrder, $request->validated());

        return $this->saved($po, "Purchase order {$po->po_number} saved.");
    }

    /**
     * Small JSON endpoint for the item picker (active items only, at most 10).
     * An empty or missing q returns the first 10 active items, alphabetical, so the
     * dropdown has something to show before the user types.
     * Needs po.create or po.edit (PurchaseOrderPolicy::searchItems).
     */
    public function itemSearch(Request $request): JsonResponse
    {
        Gate::authorize('searchItems', PurchaseOrder::class);

        $q = $request->query('q', '');
        $term = is_string($q) ? mb_substr(trim($q), 0, 100) : '';

        return response()->json([
            'items' => $this->service->searchItems($term),
        ]);
    }

    /** One place for the success toast (the flash message shared by HandleInertiaRequests). */
    private function saved(PurchaseOrder $po, string $message): RedirectResponse
    {
        return redirect()->route('purchase-orders.show', $po)->with('success', $message);
    }

    /**
     * How the form's live due date preview counts days (same settings as App\Support\DueDate).
     *
     * @return array{term: string, extension: string}
     */
    private function dueDateRules(): array
    {
        return [
            'term' => (string) config('mms.delivery.term_counting'),
            'extension' => (string) config('mms.delivery.extension_counting'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier' => $po->supplier->name,
            'status' => ['value' => $po->status->value, 'label' => $po->status->label()],
            'due_date' => $po->due_date?->toDateString(),
            'po_date' => $po->po_date?->toDateString(),
            'items_count' => (int) $po->items_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(PurchaseOrder $po): array
    {
        // Money in whole centavos (integers) so totals never drift.
        $totalCents = 0;

        $items = $po->items->map(function (PoItem $item) use (&$totalCents) {
            $lineCents = Money::cents($item->unit_price) * $item->quantity_ordered;
            $totalCents += $lineCents;

            return [
                'id' => $item->id,
                'description' => $item->description,
                'category' => $item->category->name,
                'unit' => $item->unit->name,
                'unit_price' => $item->unit_price,
                'quantity_ordered' => $item->quantity_ordered,
                'line_total' => Money::format($lineCents),
                'in_catalog' => $item->item_id !== null,
            ];
        })->values();

        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier' => $po->supplier->name,
            'source' => $po->source === PurchaseOrderSource::BizBox ? 'BizBox' : 'Manual',
            'external_id' => $po->external_id,
            'po_date' => $po->po_date?->toDateString(),
            'emailed_date' => $po->emailed_date?->toDateString(),
            'delivery_term_days' => $po->delivery_term_days,
            'due_date' => $po->due_date?->toDateString(),
            'status' => ['value' => $po->status->value, 'label' => $po->status->label()],
            'closed_at' => $po->closed_at ? DisplayTime::format($po->closed_at) : null,
            'items' => $items,
            'total' => Money::format($totalCents),
            'schedules' => $po->schedules->map(fn ($s) => $s->scheduled_date->toDateString())->values(),
            'extensions' => $po->extensions->map(fn (PoExtension $e) => [
                'id' => $e->id,
                'extra_days' => $e->extra_days,
                'reason' => $e->reason,
                'old_due_date' => $e->old_due_date->toDateString(),
                'new_due_date' => $e->new_due_date->toDateString(),
                'approved_by' => $e->approver?->name,
                'approved_at' => DisplayTime::format($e->approved_at),
            ])->values(),
            'edit' => $this->editInfo($po),
        ];
    }

    /**
     * Drives the Edit button. The reason is shown only to people who hold po.edit.
     * Hiding the button is for convenience; PurchaseOrderPolicy is the real protection.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    private function editInfo(PurchaseOrder $po): array
    {
        if (! request()->user()?->can('po.edit')) {
            return ['allowed' => false, 'reason' => null];
        }

        $result = Gate::inspect('update', $po);

        return ['allowed' => $result->allowed(), 'reason' => $result->allowed() ? null : $result->message()];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier_id' => $po->supplier_id,
            'po_date' => $po->po_date?->toDateString(),
            'emailed_date' => $po->emailed_date?->toDateString(),
            'delivery_term_days' => $po->delivery_term_days,
            'status_label' => $po->status->label(),
            'items' => $po->items->map(fn (PoItem $item) => [
                'id' => $item->id,
                'item_id' => $item->item_id,
                'item_name' => $item->item?->name,
                'item_status' => $item->item === null ? 'missing' : ($item->item->is_active ? 'ok' : 'inactive'),
                'description' => $item->description,
                'category' => $item->category->name,
                'unit' => $item->unit->name,
                'unit_price' => $item->unit_price,
                'quantity_ordered' => $item->quantity_ordered,
            ])->values(),
            'schedule_dates' => $po->schedules->map(fn ($s) => $s->scheduled_date->toDateString())->values(),
            'extension_days' => $po->extensions->pluck('extra_days')->values(),
        ];
    }
}