<?php

namespace App\Data;

/**
 * One PO line as a provider returns it. Only fields our po_items table holds.
 * itemId is null when the line is not in our catalog (a BizBox line, for example).
 * unitPrice is a decimal string such as "125.50" (no floats, so no rounding drift).
 */
final readonly class PurchaseOrderLineData
{
    public function __construct(
        public ?int $itemId,
        public string $description,
        public string $unitPrice,
        public int $quantityOrdered,
    ) {}
}