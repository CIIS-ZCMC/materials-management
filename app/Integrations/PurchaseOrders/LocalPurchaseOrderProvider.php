<?php

namespace App\Integrations\PurchaseOrders;

use App\Contracts\PurchaseOrderProvider;
use App\Data\PurchaseOrderData;
use App\Data\PurchaseOrderLineData;
use App\Enums\PurchaseOrderSource;
use App\Exceptions\PurchaseOrderNotFound;
use App\Models\PoItem;
use App\Models\PoSchedule;
use App\Models\PurchaseOrder;

/**
 * Reads a PO from our own database, by PO number. Always available.
 */
final class LocalPurchaseOrderProvider implements PurchaseOrderProvider
{
    public function source(): PurchaseOrderSource
    {
        return PurchaseOrderSource::Manual;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function fetch(string $reference): PurchaseOrderData
    {
        $po = $this->find($reference);

        return new PurchaseOrderData(
            poNumber: $po->po_number,
            supplierName: $po->supplier->name,
            poDate: $po->po_date?->toDateString(),
            emailedDate: $po->emailed_date?->toDateString(),
            deliveryTermDays: $po->delivery_term_days,
            externalId: $po->external_id,
            scheduleDates: $po->schedules
                ->map(fn (PoSchedule $schedule) => $schedule->scheduled_date->toDateString())
                ->values()
                ->all(),
            lines: $po->items
                ->map(fn (PoItem $line) => new PurchaseOrderLineData(
                    itemId: $line->item_id,
                    description: $line->description,
                    unitPrice: (string) $line->unit_price,
                    quantityOrdered: (int) $line->quantity_ordered,
                ))
                ->values()
                ->all(),
        );
    }

    /**
     * No checklist table exists and the required documents are not defined yet
     * (CONTEXT.md assumptions), so there is nothing to return. A PO that does
     * not exist is still an error, like in fetch().
     */
    public function iarChecklist(string $reference): array
    {
        $this->find($reference);

        return [];
    }

    private function find(string $reference): PurchaseOrder
    {
        $po = PurchaseOrder::query()
            ->with([
                'supplier:id,name',
                'items' => fn ($query) => $query->orderBy('id'),
                'schedules',
            ])
            ->where('po_number', trim($reference))
            ->first();

        if ($po === null) {
            throw PurchaseOrderNotFound::forReference($reference);
        }

        return $po;
    }
}