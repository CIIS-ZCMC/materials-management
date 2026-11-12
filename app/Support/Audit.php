<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * The one place the app writes to the audit trail (Spatie Activitylog).
 *
 * Every later step calls this for status changes and other business actions:
 *
 *   Audit::statusChanged($po, 'verified', 'partially delivered', reason: '...');
 *   Audit::log('extended', $po, 'Extended due date', old: ['due_date' => ...], new: [...], reason: '...');
 *
 * who    = causer (the logged-in user unless given)
 * what   = event + description
 * old/new= values before and after (NEVER pass passwords, hashes or tokens)
 * when   = created_at (stored UTC, shown in Asia/Manila)
 * subject= the model acted on (User, PO, ...)
 * reason = optional free text
 *
 * Models may define auditLabel() (e.g. "Juan Cruz (EMP-001)") so the log stays
 * readable even if the record changes later.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $old  values before the change, keyed by field
     * @param  array<string, mixed>  $new  values after the change, keyed by field
     * @param  array<string, mixed>  $extra  any other properties to keep
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        ?string $description = null,
        array $old = [],
        array $new = [],
        ?string $reason = null,
        array $extra = [],
        ?Model $causer = null,
        string $log = 'default',
    ): ?Activity {
        $properties = array_filter(
            [
                'subject_label' => $subject ? self::labelFor($subject) : null,
                'old' => $old === [] ? null : $old,
                'new' => $new === [] ? null : $new,
                'reason' => filled($reason) ? trim((string) $reason) : null,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
                ...$extra,
            ],
            fn ($value) => $value !== null && $value !== [],
        );

        if (! config('activitylog.enabled', true)) {
            return null;
        }

        $causer ??= Auth::user();

        // Writes a row to Spatie's activity_log table through its Activity
        // model. The global activity() helper is not used: it is not
        // available in v5 here, and the table columns are the stable part.
        $model = config('activitylog.activity_model', Activity::class);

        /** @var Activity $activity */
        $activity = new $model;
        $activity->forceFill([
            'log_name' => $log,
            'event' => $event,
            'description' => $description ?? Str::headline($event),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'causer_type' => $causer?->getMorphClass(),
            'causer_id' => $causer?->getKey(),
            'properties' => $properties,
        ])->save();

        return $activity;
    }

    /**
     * Shortcut for status changes (PO, delivery, DV, ...).
     *
     * @param  array<string, mixed>  $extra
     */
    public static function statusChanged(
        Model $subject,
        string $from,
        string $to,
        ?string $reason = null,
        array $extra = [],
        string $log = 'default',
    ): ?Activity {
        return self::log(
            event: 'status_changed',
            subject: $subject,
            description: 'Status changed: '.self::labelFor($subject)." ({$from} to {$to})",
            old: ['status' => $from],
            new: ['status' => $to],
            reason: $reason,
            extra: $extra,
            log: $log,
        );
    }

    public static function labelFor(Model $subject): string
    {
        if (method_exists($subject, 'auditLabel')) {
            return (string) $subject->auditLabel();
        }

        return class_basename($subject).' #'.$subject->getKey();
    }
}