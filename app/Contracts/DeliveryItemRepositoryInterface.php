<?php

namespace App\Contracts;

use App\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads and row locks of the delivered-item rows (step 6.1). The totals are NOT here: they are
 * computed by App\Support\DeliveryTotals and never stored.
 */
interface DeliveryItemRepositoryInterface
{
    /**
     * Every row of ONE delivery (voided ones included), oldest date first, with the delivery and the people.
     *
     * @return Collection<int, DeliveryItem>
     */
    public function forDelivery(int $deliveryId): Collection;

    /**
     * Every row of every delivery of ONE PO (voided rows and voided deliveries included), oldest date first.
     *
     * @return Collection<int, DeliveryItem>
     */
    public function forPurchaseOrder(int $purchaseOrderId): Collection;

    /**
     * One row of ONE delivery, locked. A row of another delivery is "not found" (404).
     */
    public function findForUpdate(int $deliveryId, int|string $id): DeliveryItem;

    /**
     * The ACTIVE rows of one delivery, locked (used when the whole delivery is voided).
     *
     * @return Collection<int, DeliveryItem>
     */
    public function activeForUpdate(int $deliveryId): Collection;
}