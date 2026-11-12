<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

/**
 * catalog.view lets a user see the list.
 * catalog.manage covers create, edit and activate/deactivate (and viewing).
 * Auto-discovered: App\Models\Item -> App\Policies\ItemPolicy.
 */
class ItemPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyPermission(['catalog.view', 'catalog.manage']);
    }

    public function create(User $actor): bool
    {
        return $actor->can('catalog.manage');
    }

    public function update(User $actor, Item $item): bool
    {
        return $actor->can('catalog.manage');
    }

    public function toggleActive(User $actor, Item $item): bool
    {
        return $actor->can('catalog.manage');
    }
}
