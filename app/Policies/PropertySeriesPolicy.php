<?php

namespace App\Policies;

use App\Models\User;

/**
 * Step 6.2: the PAR / Sub-PAR / ICS counters and the voided numbers are an Admin screen (property.manage-numbers),
 * like IarNumberPolicy. Auto-discovered: App\Models\PropertySeries -> App\Policies\PropertySeriesPolicy.
 */
class PropertySeriesPolicy
{
    public const MANAGE = 'property.manage-numbers';

    public function viewAny(User $actor): bool
    {
        return $actor->can(self::MANAGE);
    }

    public function setCounter(User $actor): bool
    {
        return $actor->can(self::MANAGE);
    }
}
