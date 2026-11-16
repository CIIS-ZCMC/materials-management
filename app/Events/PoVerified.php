<?php

namespace App\Events;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The PO Reviewer verified a PO (uploaded to verified).
 *
 * This is the hook for the 3-day delivery notice (config mms.delivery.notice_days).
 * Nothing listens yet, so nothing is sent; Phase 11 adds the listener.
 * Dispatched by PoReviewService after the transaction has committed.
 */
class PoVerified
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly ?User $actor,
    ) {}
}