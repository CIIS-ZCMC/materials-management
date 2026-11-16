<?php

namespace App\Services;

use App\Contracts\PurchaseOrderRepositoryInterface;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\AuditEntry;
use App\Support\DisplayTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Step 3.6: turns audit rows into plain arrays for the page. Read only.
 * Event and field labels come from config/mms.php (audit.events, audit.fields).
 */
class PoHistoryService
{
    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
    ) {}

    /**
     * The History tab: newest first.
     *
     * @return list<array{id: int, event: string, event_label: string, description: string, who: string|null, when: string, reason: string|null, changes: list<array{field: string, old: string|null, new: string|null}>}>
     */
    public function forPurchaseOrder(PurchaseOrder $purchaseOrder): array
    {
        $activities = $this->orders->history($purchaseOrder);
        $names = $this->namesFor($activities);

        return $activities
            ->map(fn (Activity $activity) => $this->entry($activity, $names))
            ->values()
            ->all();
    }

    /**
     * The latest escalation remarks of each given PO, keyed by PO id.
     * One audit query and one user query for the whole list page.
     *
     * @param  list<int>  $poIds
     * @return array<int, array{reason: string|null, by: string|null, at: string}>
     */
    public function latestEscalations(array $poIds): array
    {
        $rows = $this->orders->latestEscalationActivities($poIds);
        $names = $this->namesFor($rows);

        return $rows
            ->map(fn (Activity $activity) => [
                'reason' => $this->reasonOf($activity),
                'by' => $this->whoOf($activity, $names),
                'at' => DisplayTime::format($activity->created_at),
            ])
            ->all();
    }

    /**
     * @return array{reason: string|null, by: string|null, at: string}|null
     */
    public function latestEscalation(PurchaseOrder $purchaseOrder): ?array
    {
        return $this->latestEscalations([$purchaseOrder->id])[$purchaseOrder->id] ?? null;
    }

    /**
     * @param  Collection<int, Activity>  $activities
     * @return array<int, string>
     */
    private function namesFor(Collection $activities): array
    {
        $ids = $activities
            ->filter(fn (Activity $activity) => $activity->causer_type === (new User)->getMorphClass() && $activity->causer_id !== null)
            ->pluck('causer_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->orders->userNames($ids);
    }

    /** @param  array<int, string>  $names */
    private function whoOf(Activity $activity, array $names): ?string
    {
        if ($activity->causer_type !== (new User)->getMorphClass() || $activity->causer_id === null) {
            return null;
        }

        return $names[(int) $activity->causer_id] ?? null;
    }

    private function reasonOf(Activity $activity): ?string
    {
        $reason = AuditEntry::properties($activity)['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * @param  array<int, string>  $names
     * @return array{id: int, event: string, event_label: string, description: string, who: string|null, when: string, reason: string|null, changes: list<array{field: string, old: string|null, new: string|null}>}
     */
    private function entry(Activity $activity, array $names): array
    {
        $properties = AuditEntry::properties($activity);
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $new = is_array($properties['new'] ?? null) ? $properties['new'] : [];

        $fields = (array) config('mms.audit.fields', []);
        $events = (array) config('mms.audit.events', []);
        $event = (string) $activity->event;

        $changes = [];

        foreach (array_values(array_unique([...array_keys($old), ...array_keys($new)])) as $key) {
            $changes[] = [
                'field' => (string) ($fields[$key] ?? Str::headline((string) $key)),
                'old' => $this->text($old[$key] ?? null),
                'new' => $this->text($new[$key] ?? null),
            ];
        }

        return [
            'id' => (int) $activity->id,
            'event' => $event,
            'event_label' => (string) ($events[$event] ?? Str::headline($event)),
            'description' => (string) $activity->description,
            'who' => $this->whoOf($activity, $names),
            'when' => DisplayTime::format($activity->created_at),
            'reason' => $this->reasonOf($activity),
            'changes' => $changes,
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }
}