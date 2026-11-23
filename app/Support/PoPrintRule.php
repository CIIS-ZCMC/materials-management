<?php

namespace App\Support;

use App\Enums\PurchaseOrderSource;
use App\Models\PurchaseOrder;

/**
 * Which POs the system may print (step 4.6). Same style as PoEditRule: a plain message when blocked, null when allowed.
 *
 * A PO made in the system (source manual) prints at ANY status: printing only reads stored data. A BizBox PO keeps
 * its own document (the PO Bundle), so it is refused here.
 */
final class PoPrintRule
{
    public static function blockReason(PurchaseOrder $po): ?string
    {
        if ($po->source === PurchaseOrderSource::BizBox) {
            return 'This PO comes from BizBox and keeps its own document. Only POs made in this system can be printed here.';
        }

        return null;
    }
}
