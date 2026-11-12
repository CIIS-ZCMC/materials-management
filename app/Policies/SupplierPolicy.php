<?php

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;

/**
 * suppliers.view lets a user see the list.
 * suppliers.manage covers create, edit and activate/deactivate (and viewing).
 * Auto-discovered: App\Models\Supplier -> App\Policies\SupplierPolicy.
 */
class SupplierPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyPermission(['suppliers.view', 'suppliers.manage']);
    }

    public function create(User $actor): bool
    {
        return $actor->can('suppliers.manage');
    }

    public function update(User $actor, Supplier $supplier): bool
    {
        return $actor->can('suppliers.manage');
    }

    public function toggleActive(User $actor, Supplier $supplier): bool
    {
        return $actor->can('suppliers.manage');
    }
}