<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;

/**
 * catalog.view lets a user see the list.
 * catalog.manage covers create, edit and activate/deactivate (and viewing).
 * Auto-discovered: App\Models\Unit -> App\Policies\UnitPolicy.
 */
class UnitPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyPermission(['catalog.view', 'catalog.manage']);
    }

    public function create(User $actor): bool
    {
        return $actor->can('catalog.manage');
    }

    public function update(User $actor, Unit $unit): bool
    {
        return $actor->can('catalog.manage');
    }

    public function toggleActive(User $actor, Unit $unit): bool
    {
        return $actor->can('catalog.manage');
    }
}
