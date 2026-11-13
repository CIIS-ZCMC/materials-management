<?php

namespace App\Enums;

/**
 * Where a PO record came from (column purchase_orders.source).
 *
 * Manual .... created in this system by Procurement
 * BizBox .... read from BizBox (step 3.4 and Phase 13; the button stays disabled for now)
 */
enum PurchaseOrderSource: string
{
    case Manual = 'manual';
    case BizBox = 'bizbox';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::BizBox => 'BizBox',
        };
    }
}