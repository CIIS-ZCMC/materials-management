<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Spatie\Activitylog\Models\Activity;

/**
 * Reads an audit row's "properties" as a plain array, whatever cast the package uses
 * (array, collection or a JSON string). The rows are written by App\Support\Audit.
 */
final class AuditEntry
{
    /** @return array<string, mixed> */
    public static function properties(Activity $activity): array
    {
        $raw = $activity->properties;

        if ($raw instanceof Arrayable) {
            return $raw->toArray();
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}