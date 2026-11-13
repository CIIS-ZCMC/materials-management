<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * po.view lets a user see the PO list and a PO's details.
 * Later steps add methods here (create, update, verify...) with their own permissions.
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
}