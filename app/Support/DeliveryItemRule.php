<?php

namespace App\Support;

use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use App\Models\DeliveryItem;

/**
 * The one place the delivered-item rules live (step 6.1), like DeliveryRule and DeliveryDocumentRule.
 * Each method returns null when allowed, or a plain sentence saying why not.
 * DeliveryPolicy (buttons, 403) and DeliveryItemService (again, under the PO and delivery row locks) both use it.
 * The delivery must have its "purchaseOrder" relation loaded.
 *
 *                      for_uploading   complete    voided
 * add items                no            yes         no
 * void an item row         no            yes         no
 * Items already entered STAY COUNTED while the delivery is reopened (for_uploading); only adding and voiding wait
 * until it is "Documents complete" again. Totals ignore only voided rows and voided deliveries.
 *
 * Step 6.1b, ONE DELIVERY = ONE IAR = ONE QUANTITY PER PO LINE: a delivery holds at most one ACTIVE row for each PO line
 * (lineAlreadyEnteredReason). More of the same line arrives with a NEW delivery and a new IAR. A voided row does not count,
 * so a corrected line can be entered again. There is no unique index in the database (voided rows share the line). Rows
 * that were saved before this rule (two active rows for one line in one delivery) are left alone and still count in every
 * total; the rule only blocks NEW saves.
 * A closed PO, or a PO that no longer accepts deliveries (DeliveryRule::PO_STATUSES), accepts none of these.
 */
final class DeliveryItemRule
{
    public const LINE_ALREADY_ENTERED = 'This line already has a quantity on this IAR. Void it and enter it again, or record a new delivery with a new IAR.';

    public static function addBlockReason(Delivery $delivery): ?string
    {
        return self::commonBlockReason($delivery);
    }

    public static function voidBlockReason(Delivery $delivery, ?DeliveryItem $item = null): ?string
    {
        if ($reason = self::commonBlockReason($delivery)) {
            return $reason;
        }

        if ($item !== null && $item->status === DeliveryItemStatus::Voided) {
            return 'This line was already voided.';
        }

        return null;
    }

    /**
     * The PO lines that already have an ACTIVE row in this delivery. Read by the Service under the PO and delivery row
     * locks, and by the form (so a taken line is shown as taken).
     *
     * @return list<int>
     */
    public static function activeLineIds(Delivery $delivery): array
    {
        return DeliveryItem::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('status', DeliveryItemStatus::Active->value)
            ->pluck('po_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One quantity per PO line per delivery (step 6.1b).
     *
     * @param  list<int>  $activeLineIds  from activeLineIds()
     */
    public static function lineAlreadyEnteredReason(int $poItemId, array $activeLineIds): ?string
    {
        return in_array($poItemId, $activeLineIds, true) ? self::LINE_ALREADY_ENTERED : null;
    }

    private static function commonBlockReason(Delivery $delivery): ?string
    {
        if ($delivery->status === DeliveryStatus::Voided) {
            return 'This delivery was voided, so its delivered items can no longer be changed.';
        }

        $po = $delivery->purchaseOrder;

        if ($po->isClosed()) {
            return 'This PO is closed, so delivered items can no longer be changed.';
        }

        if (! in_array($po->status, DeliveryRule::PO_STATUSES, true)) {
            return 'Delivered items can be updated only for a verified PO. This PO is "'.$po->status->label().'".';
        }

        if ($delivery->status !== DeliveryStatus::Complete) {
            return 'Mark the documents of this delivery complete before updating its delivered items. Items already entered stay counted.';
        }

        return null;
    }
}
