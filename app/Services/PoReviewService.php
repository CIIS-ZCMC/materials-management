<?php

namespace App\Services;

use App\Contracts\PurchaseOrderRepositoryInterface;
use App\Enums\PurchaseOrderStatus;
use App\Events\PoEscalated;
use App\Events\PoReturnedToReviewer;
use App\Events\PoVerified;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\PoReviewRule;
use App\Support\StatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Step 3.6: verify, escalate and send back. One method per action, each in ONE
 * transaction: lock the PO row, check PoReviewRule, then StatusTransition::apply()
 * (which re-checks the enum rules and writes the audit entry in log "po" with the
 * reason). The event is dispatched after the commit, so a listener never sees a
 * rolled-back change. Permissions are checked before this (Form Request and Policy).
 */
class PoReviewService
{
    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
    ) {}

    /**
     * uploaded to verified. A PO without a due date may be verified (the page warns first).
     *
     * @throws ValidationException when the PO may not be verified (error key "form")
     */
    public function verify(PurchaseOrder $purchaseOrder, User $actor): PurchaseOrder
    {
        $po = DB::transaction(function () use ($purchaseOrder) {
            $po = $this->orders->findLocked($purchaseOrder->id);

            if (($reason = PoReviewRule::verifyBlockReason($po)) !== null) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            StatusTransition::apply($po, 'status', PurchaseOrderStatus::Verified, log: 'po');

            return $po;
        });

        PoVerified::dispatch($po, $actor);

        return $po;
    }

    /**
     * uploaded to escalated. The remarks are the audit reason.
     *
     * @throws ValidationException when the PO may not be escalated (error key "form")
     */
    public function escalate(PurchaseOrder $purchaseOrder, User $actor, string $remarks): PurchaseOrder
    {
        $remarks = trim($remarks);

        $po = DB::transaction(function () use ($purchaseOrder, $remarks) {
            $po = $this->orders->findLocked($purchaseOrder->id);

            if (($reason = PoReviewRule::escalateBlockReason($po)) !== null) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            StatusTransition::apply($po, 'status', PurchaseOrderStatus::Escalated, reason: $remarks, log: 'po');

            return $po;
        });

        PoEscalated::dispatch($po, $actor, $remarks);

        return $po;
    }

    /**
     * escalated to uploaded, with a note for the Reviewer (the audit reason). The details
     * themselves are fixed first, through the normal edit form (po.edit).
     *
     * @throws ValidationException when the PO may not be sent back (error key "form")
     */
    public function sendBack(PurchaseOrder $purchaseOrder, User $actor, string $note): PurchaseOrder
    {
        $note = trim($note);

        $po = DB::transaction(function () use ($purchaseOrder, $note) {
            $po = $this->orders->findLocked($purchaseOrder->id);

            if (($reason = PoReviewRule::sendBackBlockReason($po)) !== null) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            StatusTransition::apply($po, 'status', PurchaseOrderStatus::Uploaded, reason: $note, log: 'po');

            return $po;
        });

        PoReturnedToReviewer::dispatch($po, $actor, $note);

        return $po;
    }

    /** Escalated POs waiting for the Admin (the number on the "Escalated" tab of the PO list). */
    public function escalatedCount(): int
    {
        return $this->orders->countWithStatus(PurchaseOrderStatus::Escalated);
    }
}