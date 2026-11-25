<?php

namespace App\Repositories;

use App\Contracts\DeliveryItemRepositoryInterface;
use App\Enums\DeliveryItemStatus;
use App\Models\DeliveryItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DeliveryItemRepository implements DeliveryItemRepositoryInterface
{
    public function forDelivery(int $deliveryId): Collection
    {
        return $this->withPeople(DeliveryItem::query())
            ->where('delivery_id', $deliveryId)
            ->orderBy('delivered_on')
            ->orderBy('id')
            ->get();
    }

    public function forPurchaseOrder(int $purchaseOrderId): Collection
    {
        return $this->withPeople(DeliveryItem::query())
            ->whereHas('delivery', fn (Builder $delivery) => $delivery->where('purchase_order_id', $purchaseOrderId))
            ->orderBy('delivered_on')
            ->orderBy('id')
            ->get();
    }

    public function findForUpdate(int $deliveryId, int|string $id): DeliveryItem
    {
        return DeliveryItem::query()
            ->whereKey($id)
            ->where('delivery_id', $deliveryId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function activeForUpdate(int $deliveryId): Collection
    {
        return DeliveryItem::query()
            ->where('delivery_id', $deliveryId)
            ->where('status', DeliveryItemStatus::Active->value)
            ->lockForUpdate()
            ->get();
    }

    /**
     * purchase_order_id is selected on purpose: DeliveryPolicy loads the PO from it when a row's delivery is checked.
     *
     * @param  Builder<DeliveryItem>  $query
     * @return Builder<DeliveryItem>
     */
    private function withPeople(Builder $query): Builder
    {
        return $query->with([
            'delivery:id,purchase_order_id,iar_number,status',
            'recorder:id,name',
            'voider:id,name',
        ]);
    }
}