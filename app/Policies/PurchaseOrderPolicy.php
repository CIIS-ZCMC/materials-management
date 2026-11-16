<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\ExtensionRule;
use App\Support\PoEditRule;
use App\Support\PoReviewRule;
use Illuminate\Auth\Access\Response;

/**
 * po.view          see the PO list and a PO's details
 * po.create        create a PO in the system
 * po.edit          edit a PO, only while PoEditRule allows it (draft, uploaded, escalated);
 *                  also sends an escalated PO back to the Reviewer (PoReviewRule::sendBackBlockReason)
 * extension.manage record an extension, only while ExtensionRule allows it (verified, not closed, has a due date)
 * po.review        verify a PO, only while PoReviewRule allows it (uploaded)
 * po.escalate      escalate a PO to the Admin, only while PoReviewRule allows it (uploaded)
 * Auto-discovered: App\Models\PurchaseOrder -> App\Policies\PurchaseOrderPolicy.
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('po.view');
    }

    public function view(User $actor, PurchaseOrder $purchaseOrder): bool
    {
        return $actor->can('po.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('po.create');
    }

    /** Returns a Response so a locked PO gives a clear message with the 403. */
    public function update(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.edit')) {
            return Response::deny();
        }

        $reason = PoEditRule::blockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Record an extension (not an edit: PoEditRule does not apply). Drives the "Record extension" button. */
    public function extend(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('extension.manage')) {
            return Response::deny();
        }

        $reason = ExtensionRule::blockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Verify the PO (step 3.6). PO Reviewer job only; the Admin does not verify. */
    public function verify(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.review')) {
            return Response::deny();
        }

        $reason = PoReviewRule::verifyBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Escalate the PO to the System Admin with remarks (step 3.6). */
    public function escalate(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.escalate')) {
            return Response::deny();
        }

        $reason = PoReviewRule::escalateBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** "Send back to Reviewer" (step 3.6): the Admin, with po.edit, after fixing an escalated PO. */
    public function sendBack(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.edit')) {
            return Response::deny();
        }

        $reason = PoReviewRule::sendBackBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** The catalog search box on the PO form. */
    public function searchItems(User $actor): bool
    {
        return $actor->can('po.create') || $actor->can('po.edit');
    }
}