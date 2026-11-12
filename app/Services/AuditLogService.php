<?php

namespace App\Services;

use App\Models\User;
use App\Support\DisplayTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Reads the audit trail for the Audit Log page (read only).
 */
class AuditLogService
{
    /**
     * Clean the raw query string into the six known filters ('' = not set).
     *
     * @param  array<string, mixed>  $input
     * @return array{causer: string, from: string, to: string, subject_type: string, search: string, sort: string}
     */
    public function normalizeFilters(array $input): array
    {
        $causer = trim((string) ($input['causer'] ?? ''));
        $subjectType = trim((string) ($input['subject_type'] ?? ''));

        return [
            'causer' => ($causer === 'none' || ctype_digit($causer)) ? $causer : '',
            'from' => trim((string) ($input['from'] ?? '')),
            'to' => trim((string) ($input['to'] ?? '')),
            'subject_type' => array_key_exists($subjectType, $this->subjectTypes()) ? $subjectType : '',
            'search' => Str::limit(trim((string) ($input['search'] ?? '')), 100, ''),
            'sort' => (($input['sort'] ?? '') === 'oldest') ? 'oldest' : '', // '' = newest first
        ];
    }

    /**
     * @param  array{causer: string, from: string, to: string, subject_type: string, search: string, sort: string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = Activity::query()
            ->with('causer');

        $direction = $filters['sort'] === 'oldest' ? 'asc' : 'desc';
        $query->orderBy('created_at', $direction)->orderBy('id', $direction);

        $this->applyFilters($query, $filters);

        return $query->paginate($perPage)
            ->withQueryString()
            ->through(fn (Activity $activity) => $this->row($activity));
    }

    /**
     * Users for the "By user" filter (small system, so all of them).
     *
     * @return list<array{id: int, label: string}>
     */
    public function causerOptions(): array
    {
        return User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no'])
            ->map(fn (User $user) => ['id' => $user->id, 'label' => $user->auditLabel()])
            ->values()
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function subjectTypeOptions(): array
    {
        return collect($this->subjectTypes())
            ->map(fn (string $label, string $class) => ['value' => $class, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  array{causer: string, from: string, to: string, subject_type: string, search: string, sort: string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['causer'] === 'none') {
            $query->whereNull('causer_id');
        } elseif ($filters['causer'] !== '') {
            $query->where('causer_type', User::class)->where('causer_id', (int) $filters['causer']);
        }

        // Dates are typed in Asia/Manila; created_at is stored in UTC.
        [$start, $end] = DisplayTime::dayBoundsUtc($filters['from'] ?: null, $filters['to'] ?: null);

        if ($start) {
            $query->where('created_at', '>=', $start);
        }

        if ($end) {
            $query->where('created_at', '<=', $end);
        }

        if ($filters['subject_type'] !== '') {
            $query->where('subject_type', $filters['subject_type']);
        }

        if ($filters['search'] !== '') {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';

            $query->where(function (Builder $q) use ($like) {
                $q->where('properties->subject_label', 'like', $like)
                    ->orWhere('description', 'like', $like);
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Activity $activity): array
    {
        $raw = $activity->properties;
        $props = $raw instanceof Collection ? $raw->toArray() : (array) $raw;

        $event = (string) $activity->event;
        $causer = $activity->causer;

        return [
            'id' => $activity->id,
            'event' => $event,
            'event_label' => config("mms.audit.events.{$event}") ?? Str::headline($event ?: 'activity'),
            'description' => (string) $activity->description,
            'when' => DisplayTime::format($activity->created_at),
            'when_iso' => $activity->created_at?->toIso8601String(),
            'causer' => $causer instanceof User
                ? ['id' => $causer->id, 'name' => $causer->name, 'employee_no' => $causer->employee_no]
                : null,
            'subject' => $activity->subject_type
                ? [
                    'type' => $this->subjectTypes()[$activity->subject_type] ?? Str::headline(class_basename($activity->subject_type)),
                    'label' => $props['subject_label'] ?? '#'.$activity->subject_id,
                ]
                : null,
            'changes' => $this->changes($props),
            'reason' => $props['reason'] ?? null,
            'ip' => $props['ip'] ?? null,
        ];
    }

    /**
     * Turn the stored old/new values into display rows.
     * old and new both set = "old to new"; only new = a value that was set
     * (or added); only old = a value that was removed.
     *
     * @param  array<string, mixed>  $props
     * @return list<array{label: string, old: ?string, new: ?string}>
     */
    private function changes(array $props): array
    {
        $old = (array) ($props['old'] ?? []);
        $new = (array) ($props['new'] ?? []);
        $rows = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $rows[] = [
                'label' => $this->fieldLabel((string) $key),
                'old' => array_key_exists($key, $old) ? $this->text($old[$key]) : null,
                'new' => array_key_exists($key, $new) ? $this->text($new[$key]) : null,
            ];
        }

        if (! empty($props['permissions_added'])) {
            $rows[] = ['label' => 'Permissions added', 'old' => null, 'new' => $this->text($props['permissions_added'])];
        }

        if (! empty($props['permissions_removed'])) {
            $rows[] = ['label' => 'Permissions removed', 'old' => $this->text($props['permissions_removed']), 'new' => null];
        }

        return $rows;
    }

    private function fieldLabel(string $key): string
    {
        $configured = config("mms.audit.fields.{$key}");

        return is_string($configured) ? $configured : Str::headline($key);
    }

    private function text(mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] ? '—' : implode(', ', array_map(fn ($v) => (string) $v, $value));
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /**
     * @return array<string, string>
     */
    private function subjectTypes(): array
    {
        return (array) config('mms.audit.subject_types', []);
    }
}