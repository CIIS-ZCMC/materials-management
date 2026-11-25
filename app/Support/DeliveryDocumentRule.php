<?php

namespace App\Support;

use App\Enums\DeliveryStatus;
use App\Enums\PoDocumentStatus;
use App\Models\Delivery;
use App\Models\PoDocument;

/**
 * What may be done with the files of ONE delivery (step 5.3), like PoDocumentRule does for the PO Bundle.
 * Each method returns null when allowed, or a plain sentence. DeliveryPolicy (buttons, 403) and
 * DeliveryDocumentService / PoDocumentService (again, under the PO and delivery row locks) both use it.
 * The delivery must have its "purchaseOrder" relation loaded.
 *
 *                       for_uploading   complete                    voided
 * add a file                yes         no (reopen first)           no
 * void / replace a file     yes         yes (a void may reopen)     no
 * mark complete             if no required file is missing   -      no
 * reopen (reason)           -           yes                         no
 * A closed PO accepts none of these. The required list is config/mms.php (PoDocumentChecklist::deliveryRequired()).
 */
final class DeliveryDocumentRule
{
    public static function addBlockReason(Delivery $delivery): ?string
    {
        if ($reason = self::commonBlockReason($delivery)) {
            return $reason;
        }

        if ($delivery->status === DeliveryStatus::Complete) {
            return 'This delivery is "Documents complete". Reopen it for uploading (with a reason) before adding files.';
        }

        return null;
    }

    public static function voidBlockReason(Delivery $delivery): ?string
    {
        return self::commonBlockReason($delivery);
    }

    public static function replaceBlockReason(Delivery $delivery): ?string
    {
        return self::commonBlockReason($delivery);
    }

    /** @param  list<string>  $missing labels from missing() */
    public static function completeBlockReason(Delivery $delivery, array $missing): ?string
    {
        if ($reason = self::commonBlockReason($delivery)) {
            return $reason;
        }

        if ($delivery->status === DeliveryStatus::Complete) {
            return 'This delivery is already "Documents complete".';
        }

        if ($missing !== []) {
            return 'Cannot mark this delivery complete. Missing: '.implode(', ', $missing).'. Upload the missing file'.(count($missing) === 1 ? '' : 's').' first.';
        }

        return null;
    }

    public static function reopenBlockReason(Delivery $delivery): ?string
    {
        if ($reason = self::commonBlockReason($delivery)) {
            return $reason;
        }

        return $delivery->status === DeliveryStatus::Complete ? null : 'Only a "Documents complete" delivery can be reopened for uploading.';
    }

    /**
     * Labels of the required delivery types that have no ACTIVE file, in the configured order.
     *
     * @return list<string>
     */
    public static function missing(Delivery $delivery): array
    {
        $have = PoDocument::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('status', PoDocumentStatus::Active->value)
            ->pluck('type')
            ->map(fn ($type) => $type instanceof \BackedEnum ? $type->value : (string) $type)
            ->unique()
            ->all();

        $missing = [];

        foreach (PoDocumentChecklist::deliveryRequired() as $key => $label) {
            if (! in_array($key, $have, true)) {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    private static function commonBlockReason(Delivery $delivery): ?string
    {
        if ($delivery->status === DeliveryStatus::Voided) {
            return 'This delivery was voided, so its documents can no longer be changed.';
        }

        if ($delivery->purchaseOrder->isClosed()) {
            return 'This PO is closed, so the documents of its deliveries can no longer be changed.';
        }

        return null;
    }
}
