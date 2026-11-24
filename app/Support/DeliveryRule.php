<?php

namespace App\Support;

use App\Enums\DeliveryStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\PurchaseOrder;

/**
 * The one place the delivery rules live (step 5.2), like PoEditRule and PoDocumentRule.
 * Each method returns null when the action is allowed, or a plain sentence saying why not.
 * DeliveryPolicy and DeliveryService (again, under the row locks) both use it.
 *
 *   add   a PO accepts a new delivery only while its status is in PO_STATUSES and it is not closed
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

    public static function addBlockReason(PurchaseOrder $po): ?string
    {
        return self::poBlockReason($po);
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
