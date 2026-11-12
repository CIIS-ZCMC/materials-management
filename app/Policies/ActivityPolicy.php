<?php

namespace App\Policies;

use App\Models\User;

/**
 * audit.view covers reading the audit log.
 * Not auto-discovered (the model lives in the Spatie package), so it is
 * registered in AppServiceProvider with Gate::policy().
 */
class ActivityPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('audit.view');
    }
}
