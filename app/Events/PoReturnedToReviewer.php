<?php

namespace App\Events;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The Admin sent an escalated PO back to the PO Reviewer (escalated to uploaded).
 * Nothing listens yet, so nothing is sent.
 * Dispatched by PoReviewService after the transaction has committed.
 */
class PoReturnedToReviewer
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly ?User $actor,
        public readonly string $note,
    ) {}
}