<?php

namespace App\Policies;

use App\Models\ItemCategory;
use App\Models\User;

/**
 * catalog.view lets a user see the list.
 * catalog.manage covers create, edit and activate/deactivate (and viewing).
 * Auto-discovered: App\Models\ItemCategory -> App\Policies\ItemCategoryPolicy.
 */
class ItemCategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyPermission(['catalog.view', 'catalog.manage']);
    }

    public function create(User $actor): bool
    {
        return $actor->can('catalog.manage');
    }

    public function update(User $actor, ItemCategory $category): bool
    {
        return $actor->can('catalog.manage');
    }

    public function toggleActive(User $actor, ItemCategory $category): bool
    {
        return $actor->can('catalog.manage');
    }
}
