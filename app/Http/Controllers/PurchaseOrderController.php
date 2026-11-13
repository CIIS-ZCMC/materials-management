<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Models\PoExtension;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderService;
use App\Support\DisplayTime;
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
            $lineCents = $this->cents($item->unit_price) * $item->quantity_ordered;
            $totalCents += $lineCents;

            return [
                'id' => $item->id,
                'description' => $item->description,
                'category' => $item->category->name,
                'unit' => $item->unit->name,
                'unit_price' => $item->unit_price,
                'quantity_ordered' => $item->quantity_ordered,
                'line_total' => $this->money($lineCents),
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
            'total' => $this->money($totalCents),
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
        ];
    }

    private function cents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function money(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}