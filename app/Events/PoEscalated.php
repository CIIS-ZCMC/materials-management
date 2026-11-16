<?php

namespace App\Events;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The PO Reviewer escalated a PO to the System Admin (uploaded to escalated).
 * Nothing listens yet, so nothing is sent.
 * Dispatched by PoReviewService after the transaction has committed.
 */
class PoEscalated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PurchaseOrder $purchaseOrder,
        public readonly ?User $actor,
        public readonly string $reason,
    ) {}
}