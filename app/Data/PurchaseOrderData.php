<?php

namespace App\Data;

/**
 * PO details as a provider returns them. Only fields our tables already hold.
 * Dates are plain Y-m-d strings (like the rest of the app), or null.
 */
final readonly class PurchaseOrderData
{
    /**
     * @param  list<string>  $scheduleDates  Y-m-d, oldest first
     * @param  list<PurchaseOrderLineData>  $lines
     */
    public function __construct(
        public string $poNumber,
        public string $supplierName,
        public ?string $poDate,
        public ?string $emailedDate,
        public ?int $deliveryTermDays,
        public ?string $externalId,
        public array $scheduleDates,
        public array $lines,
    ) {}
}