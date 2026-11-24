<?php

namespace App\Contracts;

use App\Models\Delivery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reads of deliveries (step 5.2). Writes stay in DeliveryService, inside its transactions,
 * as in IarNumberService. Standalone on purpose (does not extend BaseRepositoryInterface).
 */
interface DeliveryRepositoryInterface
{
    /** Columns the list may be sorted by. Anything else falls back to date_received. */
    public const SORTABLE = ['date_received', 'iar_number', 'amount_cents', 'invoice_no', 'created_at'];

    /**
     * @param  array<string, string>  $filters  already cleaned by DeliveryService::cleanFilters()
     * @return LengthAwarePaginator<int, Delivery>
     */
    public function paginateFiltered(array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Every delivery of a PO (voided ones included), newest first.
     *
     * @return Collection<int, Delivery>
     */
    public function forPurchaseOrder(int $purchaseOrderId): Collection;

    /** One delivery with a row lock. Call inside a transaction. */
    public function findForUpdate(int|string $id): Delivery;
}
