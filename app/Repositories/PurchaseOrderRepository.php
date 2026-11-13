<?php

namespace App\Repositories;

use App\Contracts\PurchaseOrderRepositoryInterface;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<PurchaseOrder>
 */
class PurchaseOrderRepository extends BaseRepository implements PurchaseOrderRepositoryInterface
{
    public const DEFAULT_SORT = 'due_date';

    public const DEFAULT_DIRECTION = 'asc';

    /**
     * The ONLY sort keys the list accepts (key sent by the page => column or alias).
     * 'supplier' is a special case (sorted by the supplier's name).
     */
    private const SORTABLE = [
        'po_number' => 'po_number',
        'supplier' => 'supplier',
        'status' => 'status',
        'due_date' => 'due_date',
        'po_date' => 'po_date',
        'items' => 'items_count',
    ];

    /** Empty dates always go to the end, in both directions. */
    private const NULLS_LAST = ['due_date', 'po_date'];

    public function __construct(PurchaseOrder $purchaseOrder)
    {
        parent::__construct($purchaseOrder);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->model->newQuery()
            ->select([
                'purchase_orders.id',
                'purchase_orders.po_number',
                'purchase_orders.supplier_id',
                'purchase_orders.status',
                'purchase_orders.due_date',
                'purchase_orders.po_date',
            ])
            ->with('supplier:id,name')
            ->withCount('items')
            ->filter(['search' => $filters['search'] ?? '']);

        if (($filters['status'] ?? '') !== '') {
            $query->where('purchase_orders.status', $filters['status']);
        }

        if (($filters['supplier'] ?? '') !== '') {
            $query->where('purchase_orders.supplier_id', (int) $filters['supplier']);
        }

        if (($filters['from'] ?? '') !== '') {
            $query->where('purchase_orders.po_date', '>=', $filters['from']);
        }

        if (($filters['to'] ?? '') !== '') {
            $query->where('purchase_orders.po_date', '<=', $filters['to']);
        }

        if (($filters['view'] ?? 'active') === 'closed') {
            $query->whereNotNull('purchase_orders.closed_at');
        } else {
            $query->open();
        }

        [$sort, $direction] = $this->resolveSort($filters['sort'] ?? null, $filters['direction'] ?? null);
        $this->applySort($query, $sort, $direction);

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function resolveSort(?string $sort, ?string $direction): array
    {
        if ($sort === null || ! array_key_exists($sort, self::SORTABLE)) {
            return [self::DEFAULT_SORT, self::DEFAULT_DIRECTION];
        }

        return [$sort, $direction === 'desc' ? 'desc' : 'asc'];
    }

    /**
     * @return Collection<int, Supplier>
     */
    public function supplierOptions(): Collection
    {
        return Supplier::query()->orderBy('name')->get(['id', 'name', 'is_active']);
    }

    public function loadDetail(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $purchaseOrder->load([
            'supplier:id,name',
            'items' => fn ($query) => $query->orderBy('id'),
            'items.category:id,name',
            'items.unit:id,name',
            'schedules',
            'extensions.approver:id,name',
        ]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<PurchaseOrder>  $query
     */
    private function applySort($query, string $sort, string $direction): void
    {
        $column = self::SORTABLE[$sort];

        if ($sort === 'supplier') {
            $query->orderBy(
                Supplier::query()->select('name')->whereColumn('suppliers.id', 'purchase_orders.supplier_id'),
                $direction,
            );
        } elseif (in_array($sort, self::NULLS_LAST, true)) {
            // "is null" gives 0 for a date and 1 for none, so dates come first.
            $query->orderByRaw("purchase_orders.{$column} is null")
                ->orderBy("purchase_orders.{$column}", $direction);
        } else {
            $query->orderBy($column === 'items_count' ? $column : "purchase_orders.{$column}", $direction);
        }

        // Stable order for rows with the same value.
        $query->orderBy('purchase_orders.id');
    }
}