<?php

namespace App\Support;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;

/**
 * The one place that says whether a PO's details may still be edited (step 3.3).
 * Used by PurchaseOrderPolicy (who may open/save the form) and by
 * PurchaseOrderService (a second check inside the save transaction).
 *
 * Editable: draft, uploaded, escalated (the Admin fixes escalated POs).
 * Locked:   verified and later, and every BizBox-source PO.
 */
final class PoEditRule
{
    /** @var list<PurchaseOrderStatus> */
    private const EDITABLE = [
        PurchaseOrderStatus::Draft,
        PurchaseOrderStatus::Uploaded,
        PurchaseOrderStatus::Escalated,
    ];

    /** A message when the PO is locked, null when it may be edited. */
    public static function blockReason(PurchaseOrder $po): ?string
    {
        if ($po->source === PurchaseOrderSource::BizBox) {
            return 'This PO comes from BizBox and cannot be edited here.';
        }

        if (! in_array($po->status, self::EDITABLE, true)) {
            return 'A PO that is "'.strtolower($po->status->label()).'" can no longer be edited. '
                .'Only draft, uploaded and escalated POs can be edited.';
        }

        return null;
    }
}