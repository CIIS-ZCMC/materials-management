<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\PoEditRule;
use Illuminate\Auth\Access\Response;

/**
 * po.view          see the PO list and a PO's details
 * po.create        create a PO in the system
 * po.edit          edit a PO, only while PoEditRule allows it (draft, uploaded, escalated)
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

    /** The catalog search box on the PO form. */
    public function searchItems(User $actor): bool
    {
        return $actor->can('po.create') || $actor->can('po.edit');
    }
}