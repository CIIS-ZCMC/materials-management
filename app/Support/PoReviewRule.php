<?php

namespace App\Support;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;

/**
 * The one place that decides what the PO Reviewer and the Admin may do in step 3.6.
 * Used by PurchaseOrderPolicy (button and message) and by PoReviewService (again,
 * under the row lock). StatusTransition::apply() is the last line of defence.
 *
 *  - verify:    uploaded only
 *  - escalate:  uploaded only
 *  - send back: escalated only, and never a BizBox-source PO (its details cannot be
 *               fixed here, so sending it back would only create a loop)
 */
final class PoReviewRule
{
    /** @return string|null why the PO cannot be verified, or null when it can */
    public static function verifyBlockReason(PurchaseOrder $po): ?string
    {
        return self::reviewBlockReason($po);
    }

    /** @return string|null why the PO cannot be escalated, or null when it can */
    public static function escalateBlockReason(PurchaseOrder $po): ?string
    {
        return self::reviewBlockReason($po);
    }

    /** @return string|null why the PO cannot be sent back to the Reviewer, or null when it can */
    public static function sendBackBlockReason(PurchaseOrder $po): ?string
    {
        if ($po->status !== PurchaseOrderStatus::Escalated) {
            return "Only escalated POs can be sent back to the Reviewer. This PO is {$po->status->label()}.";
        }

        if ($po->source === PurchaseOrderSource::BizBox) {
            return 'This PO comes from BizBox, so its details cannot be fixed here and it cannot be sent back. '
                .'Correct it in BizBox; reading BizBox POs again comes with the BizBox integration (Phase 13).';
        }

        return null;
    }

    private static function reviewBlockReason(PurchaseOrder $po): ?string
    {
        return match ($po->status) {
            PurchaseOrderStatus::Uploaded => null,
            PurchaseOrderStatus::Draft => 'This PO is still a draft. The PO Bundle must be uploaded before it can be reviewed.',
            PurchaseOrderStatus::Escalated => 'This PO was escalated to the Admin. It can be reviewed again once the Admin sends it back.',
            default => "Only uploaded POs can be reviewed. This PO is {$po->status->label()}.",
        };
    }
}