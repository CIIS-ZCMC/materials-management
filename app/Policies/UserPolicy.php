<?php

namespace App\Policies;

use App\Models\User;

/**
 * users.manage covers list, create, edit, activate/deactivate, reset password.
 * roles.manage covers changing a role or permissions (manageAccess).
 * Auto-discovered: App\Models\User -> App\Policies\UserPolicy.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('users.manage');
    }

    public function toggleActive(User $actor, User $target): bool
    {
        return $actor->can('users.manage');
    }

    public function resetPassword(User $actor, User $target): bool
    {
        return $actor->can('users.manage');
    }

    public function manageAccess(User $actor): bool
    {
        return $actor->can('roles.manage');
    }
}