<?php

namespace App\Enums;

use App\Contracts\PersonnelDirectory;

/**
 * The part a person plays on one delivery (step 5.2). Stored in delivery_signatories.role.
 * directoryRole() is the role name the PersonnelDirectory understands, so the directory's
 * own spelling lives in one place.
 */
enum DeliverySignatoryRole: string
{
    case Inspector = 'inspector';
    case EndUser = 'end_user';

    public function label(): string
    {
        return match ($this) {
            self::Inspector => 'Inspector',
            self::EndUser => 'End-user',
        };
    }

    public function directoryRole(): string
    {
        return match ($this) {
            self::Inspector => PersonnelDirectory::ROLE_INSPECTOR,
            self::EndUser => PersonnelDirectory::ROLE_END_USER,
        };
    }
}
