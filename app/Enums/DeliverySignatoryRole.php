<?php

namespace App\Enums;

/**
 * The part a person plays on one delivery (step 5.2). Stored in delivery_signatories.role.
 * Step 5.2b (decision 58): the people are typed by hand, so the enum no longer knows the
 * PersonnelDirectory's role names.
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
}