<?php

namespace App\Support;

use App\Enums\DeliveryProgress;
use App\Enums\DeliveryStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\PurchaseOrder;

/**
 * The one place the delivery rules live (step 5.2), like PoEditRule and PoDocumentRule.
 * Each method returns null when the action is allowed, or a plain sentence saying why not.
 * DeliveryPolicy and DeliveryService (again, under the row locks) both use it.
 *
 *   add   a PO accepts a new delivery only while its status is in PO_STATUSES and it is not closed.
 *         Step 6.1e: and, only when config mms.delivery.allow_after_full is false, only while the PO is not
 *         fully delivered (DeliveryProgress::Full, derived by DeliveryTotals, never stored). Default true = nothing blocked.
 *   edit  a delivery is edited only while For uploading, and only while its PO still accepts deliveries
 *   void  a delivery is voided unless it is already voided or its PO is closed
 */
final class DeliveryRule
{
    /**
     * PO statuses that accept a delivery (open question Q2, default: verified only).
     * To allow another status later, add it here; nothing else needs to change.
     *
     * @var list<PurchaseOrderStatus>
     */
    public const PO_STATUSES = [PurchaseOrderStatus::Verified];

    /**
     * Edit is allowed only in these delivery statuses.
     *
     * @var list<DeliveryStatus>
     */
    public const EDIT_STATUSES = [DeliveryStatus::ForUploading];

    /** Step 6.1e: the sentence when the switch is off and the PO is already fully delivered. */
    public const AFTER_FULL_BLOCKED = 'This PO is already fully delivered, so no new delivery can be recorded.';

    /**
     * Step 6.1e: may a delivery still be recorded after the PO is fully delivered? Config mms.delivery.allow_after_full
     * (env MMS_DELIVERY_AFTER_FULL), default true. A missing key counts as true.
     */
    public static function allowAfterFull(): bool
    {
        return (bool) config('mms.delivery.allow_after_full', true);
    }

    /**
     * Only the ADD rule looks at "fully delivered": edit and void of an existing delivery, and item entry, are never
     * affected. With the switch on (default) the totals are not even read, so this costs no query.
     */
    public static function addBlockReason(PurchaseOrder $po): ?string
    {
        $reason = self::poBlockReason($po);

        if ($reason !== null) {
            return $reason;
        }

        if (! self::allowAfterFull() && DeliveryTotals::progress($po->id) === DeliveryProgress::Full) {
            return self::AFTER_FULL_BLOCKED;
        }

        return null;
    }

    public static function editBlockReason(Delivery $delivery): ?string
    {
        if ($delivery->status === DeliveryStatus::Voided) {
            return 'This delivery was voided and can no longer be changed.';
        }

        if (! in_array($delivery->status, self::EDIT_STATUSES, true)) {
            return 'Only a delivery that is still "For uploading" can be edited.';
        }

        return self::poBlockReason($delivery->purchaseOrder);
    }

    public static function voidBlockReason(Delivery $delivery): ?string
    {
        if ($delivery->status === DeliveryStatus::Voided) {
            return 'This delivery is already voided.';
        }

        if ($delivery->purchaseOrder->isClosed()) {
            return 'This PO is closed, so its deliveries can no longer be voided.';
        }

        return null;
    }

    private static function poBlockReason(PurchaseOrder $po): ?string
    {
        if ($po->isClosed()) {
            return 'This PO is closed, so no delivery can be recorded or changed.';
        }

        if (! in_array($po->status, self::PO_STATUSES, true)) {
            return 'Deliveries can be recorded only for a verified PO. This PO is "'.$po->status->label().'".';
        }

        return null;
    }
}