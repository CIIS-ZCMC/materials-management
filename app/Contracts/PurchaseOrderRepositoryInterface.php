<?php

namespace App\Contracts;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepositoryInterface<PurchaseOrder>
 */
interface PurchaseOrderRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Paginated PO list: search, status, supplier, PO date range, Active/Closed, sort.
     * Expects filters already cleaned by PurchaseOrderService::cleanFilters().
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Checks the requested sort against the fixed list of allowed columns.
     * Anything unknown falls back to due date, soonest first.
     *
     * @return array{0: string, 1: string} [sort key, 'asc'|'desc']
     */
    public function resolveSort(?string $sort, ?string $direction): array;

    /**
     * Every supplier (inactive ones too, old POs keep them) for the filter box.
     *
     * @return Collection<int, Supplier>
     */
    public function supplierOptions(): Collection;

    /**
     * Loads everything the detail page needs, in a few queries.
     */
    public function loadDetail(PurchaseOrder $purchaseOrder): PurchaseOrder;
}