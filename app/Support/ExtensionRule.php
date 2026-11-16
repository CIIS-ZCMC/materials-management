<?php

namespace App\Support;

use App\Models\PurchaseOrder;

/**
 * The one place that decides "may this PO be extended". Extensions are not
 * edits, so PoEditRule does not apply. Used by PurchaseOrderPolicy (button and
 * message) and by PoExtensionService (again, under the row lock).
 *
 * Recommended rules (step 3.5):
 *  - closed POs: never
 *  - status: verified only
 *  - no due date (no emailed date or term 0): never
 * Overdue and past-grace POs may still be extended (suppliers ask when late).
 */
final class ExtensionRule
{
    /** Stored statuses that may be extended. */
    public const ALLOWED_STATUSES = ['verified'];

    /** @return string|null why the PO cannot be extended, or null when it can */
    public static function blockReason(PurchaseOrder $po): ?string
    {
        if ($po->isClosed()) {
            return 'This PO is closed, so it can no longer be extended.';
        }

        if (! in_array($po->status->value, self::ALLOWED_STATUSES, true)) {
            return "Only verified POs can be extended. This PO is {$po->status->label()}.";
        }

        if ($po->due_date === null) {
            return 'This PO has no due date (no emailed date or no delivery term), so it cannot be extended.';
        }

        return null;
    }
}