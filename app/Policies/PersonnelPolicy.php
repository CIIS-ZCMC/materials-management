<?php

namespace App\Policies;

use App\Models\Personnel;
use App\Models\User;

/**
 * Read only: nobody creates or edits people here (they come from UMIS).
 * Searching is for the screens that pick people: the Encoder (inspectors and end-user on a
 * delivery) and the Inventory Team (custodians for PAR / Sub-PAR / ICS). Existing permissions only.
 * Auto-discovered: App\Models\Personnel -> App\Policies\PersonnelPolicy.
 */
class PersonnelPolicy
{
    public const PERMISSIONS = [
        'delivery.encode',
        'delivery.upload-docs',
        'par.generate',
        'ics.generate',
    ];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyPermission(self::PERMISSIONS);
    }
}