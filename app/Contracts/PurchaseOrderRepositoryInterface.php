<?php

namespace App\Contracts;

use App\Models\Item;
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

    /**
     * Loads everything the edit form needs (lines with their catalog item, schedule dates,
     * approved extension days), in a few queries.
     */
    public function loadForEdit(PurchaseOrder $purchaseOrder): PurchaseOrder;

    /**
     * Re-reads the PO with a row lock (call inside a transaction), with the relations the
     * audit comparison needs: supplier, lines (item, category, unit) and schedule dates.
     */
    public function findLocked(int $id): PurchaseOrder;

    /**
     * Suppliers for the PO form: active ones, plus $keepSupplierId (an existing PO's
     * current supplier, even if inactive).
     *
     * @return Collection<int, Supplier>
     */
    public function supplierChoices(?int $keepSupplierId = null): Collection;

    /**
     * Active catalog items whose name starts with the term first, then contains it.
     * At most $limit rows, with category and unit names.
     *
     * @return Collection<int, Item>
     */
    public function searchActiveItems(string $term, int $limit = 10): Collection;

    /**
     * Catalog items by id (active or not), keyed by id, with category and unit names.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Item>
     */
    public function catalogItemsByIds(array $ids): Collection;
}