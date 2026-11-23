<?php

namespace App\Policies;

use App\Models\User;

/** The IAR numbers screen belongs to whoever holds iar.manage (the Admin role). */
class IarNumberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('iar.manage');
    }

    public function setCounter(User $user): bool
    {
        return $user->can('iar.manage');
    }
}