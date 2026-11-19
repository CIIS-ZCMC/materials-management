<?php

namespace App\Support;

use App\Models\PoItem;
use App\Models\PoSchedule;
use App\Models\PoScheduleItem;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The ONE place for the "quantity per line and per delivery column" rules (step 3.3c, form 04A).
 *
 * A PO may state, for every line, how many units arrive in each column:
 *  - the first column, "within the delivery term from receipt of the PO" (po_items.term_quantity;
 *    it exists only when the PO has a delivery term, and its heading number is the delivery term itself);
 *  - one column per scheduled date (po_schedules + po_schedule_items).
 * A PO without a split keeps plain dates, exactly as before step 3.3c.
 *
 * Request contract (all optional; the flat key "schedule_dates" keeps working):
 *  - schedule_columns: list of { id: int|null, date: Y-m-d, quantities: list<int> }. When this key is
 *    present the split is in use, "schedule_dates" is ignored, and quantities[i] belongs to items[i]
 *    (matched by POSITION, because a new line has no id yet).
 *  - items.*.term_quantity: the first column's quantity for that line.
 *
 * Rules (check()):
 *  - every line and every column has a whole number of 0 or more (zero is stored);
 *  - per line: term quantity + all date quantities = quantity ordered, exactly;
 *  - every date delivers at least one unit across the lines;
 *  - the term column needs a delivery term; no more than max_date_columns date columns;
 *  - dates are distinct.
 * Saving WITHOUT schedule_columns removes the split (clear()), so quantities that no longer add up
 * can never stay behind.
 *
 * To change a rule later, change it here (and the matching limit in config/mms.php, print.*).
 */
final class ScheduleSplit
{
    /** The most date columns of the printed Delivery Schedule (config/mms.php, print.max_date_columns). */
    public static function maxDateColumns(): int
    {
        return max(1, (int) config('mms.print.max_date_columns', 4));
    }

    /**
     * Does this (validated) input use the split? The key must be present as a list, and it must
     * carry something: date columns, or quantities for the term column.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isActive(array $data): bool
    {
        $columns = $data['schedule_columns'] ?? null;

        if (! is_array($columns)) {
            return false;
        }

        if ($columns !== []) {
            return true;
        }

        if (! self::hasTerm($data)) {
            return false;
        }

        foreach ((array) ($data['items'] ?? []) as $line) {
            $term = is_array($line) ? ($line['term_quantity'] ?? null) : null;

            if ($term !== null && $term !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Throws a ValidationException (keys the form shows under the right input) when the split breaks a rule.
     * Does nothing when the split is not in use. Call it before anything is written.
     *
     * @param  array<string, mixed>  $data  validated input
     *
     * @throws ValidationException
     */
    public static function check(array $data): void
    {
        if (! self::isActive($data)) {
            return;
        }

        $lines = array_values((array) ($data['items'] ?? []));
        $columns = array_values((array) $data['schedule_columns']);
        $hasTerm = self::hasTerm($data);
        $max = self::maxDateColumns();
        $errors = [];

        if (count($columns) > $max) {
            $errors['schedule_columns'] = "A PO can have at most {$max} date columns (the printed Delivery Schedule has no room for more).";
        }

        $matrix = [];
        $aligned = true;
        $seen = [];

        foreach ($columns as $c => $column) {
            $date = (string) ($column['date'] ?? '');

            if (isset($seen[$date])) {
                $errors["schedule_columns.{$c}.date"] = 'This date is repeated.';
            }

            $seen[$date] = true;

            $quantities = array_values((array) ($column['quantities'] ?? []));

            if (count($quantities) !== count($lines)) {
                $errors["schedule_columns.{$c}.quantities"] = 'The quantities do not match the item lines. Reload the page and try again.';
                $aligned = false;

                continue;
            }

            $matrix[$c] = array_map('intval', $quantities);

            if (array_sum($matrix[$c]) < 1 && ! isset($errors["schedule_columns.{$c}.date"])) {
                $errors["schedule_columns.{$c}.date"] = 'This date delivers nothing. Enter a quantity for at least one line, or remove the date.';
            }
        }

        foreach ($lines as $i => $line) {
            $term = is_array($line) ? ($line['term_quantity'] ?? null) : null;
            $term = $term === '' ? null : $term;

            if ($hasTerm && $term === null) {
                $errors["items.{$i}.term_quantity"] = 'Enter the quantity due within the delivery term (0 if none).';
            } elseif (! $hasTerm && (int) $term > 0) {
                $errors["items.{$i}.term_quantity"] = 'A quantity within the delivery term needs a delivery term on the PO.';
            }

            if (! $aligned) {
                continue;
            }

            $sum = $hasTerm ? (int) $term : 0;

            foreach ($matrix as $quantities) {
                $sum += $quantities[$i];
            }

            $ordered = (int) ($line['quantity_ordered'] ?? 0);

            if ($sum !== $ordered) {
                $errors["items.{$i}.quantity_ordered"] = "The quantities per column add up to {$sum}, but the quantity ordered is {$ordered}.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Saves the date columns and their quantities (and each line's term quantity). Call it inside the
     * same transaction, after check() and after the lines are saved. Does the work of syncSchedules() too.
     *
     * A column keeps its stored row: matched by id first, then (when the browser sent no id) by an
     * identical date. A changed date is updated in place, so its quantities are never lost. Columns no
     * longer sent are deleted with their quantities.
     *
     * @param  array<string, mixed>  $data  validated input
     * @param  list<int>  $lineIds  ids of the saved lines, in the same order as $data['items']
     *
     * @throws ValidationException
     */
    public static function sync(PurchaseOrder $po, array $data, array $lineIds): void
    {
        $columns = array_values((array) ($data['schedule_columns'] ?? []));
        $lines = array_values((array) ($data['items'] ?? []));
        $hasTerm = self::hasTerm($data);

        if (count($lineIds) !== count($lines)) {
            throw ValidationException::withMessages(['form' => 'The lines changed while saving. Reload the page and try again.']);
        }

        $existing = PoSchedule::query()->where('purchase_order_id', $po->id)->get()->keyBy('id');

        // 1. Which stored row does each column use?
        $claimed = []; // column index => schedule id
        $taken = [];   // schedule id => true

        foreach ($columns as $c => $column) {
            $id = $column['id'] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            $id = (int) $id;

            if (! $existing->has($id) || isset($taken[$id])) {
                throw ValidationException::withMessages([
                    'form' => 'A date column no longer belongs to this purchase order. Reload the page and try again.',
                ]);
            }

            $claimed[$c] = $id;
            $taken[$id] = true;
        }

        foreach ($columns as $c => $column) {
            if (isset($claimed[$c])) {
                continue;
            }

            $match = $existing->first(
                fn (PoSchedule $row) => ! isset($taken[$row->id]) && $row->scheduled_date->toDateString() === $column['date'],
            );

            if ($match !== null) {
                $claimed[$c] = $match->id;
                $taken[$match->id] = true;
            }
        }

        // 2. Delete the rows no column uses (their quantities go first; the foreign key would also do it).
        $gone = $existing->keys()->reject(fn ($id) => isset($taken[$id]))->values()->all();

        if ($gone !== []) {
            PoScheduleItem::query()->whereIn('po_schedule_id', $gone)->delete();
            PoSchedule::query()->whereIn('id', $gone)->delete();
        }

        // 3. Move changed dates in place. Two steps through free dates, so swapping two dates cannot
        //    hit the unique key (purchase order + date) halfway.
        $moves = [];

        foreach ($claimed as $c => $id) {
            if ($existing->get($id)->scheduled_date->toDateString() !== $columns[$c]['date']) {
                $moves[$id] = $columns[$c]['date'];
            }
        }

        if ($moves !== []) {
            $used = array_flip(array_merge(
                $existing->filter(fn (PoSchedule $row) => isset($taken[$row->id]))
                    ->map(fn (PoSchedule $row) => $row->scheduled_date->toDateString())
                    ->values()
                    ->all(),
                array_column($columns, 'date'),
            ));

            $cursor = Carbon::parse('1900-01-01');

            foreach (array_keys($moves) as $id) {
                do {
                    $temporary = $cursor->toDateString();
                    $cursor->addDay();
                } while (isset($used[$temporary]));

                $used[$temporary] = true;
                PoSchedule::query()->whereKey($id)->update(['scheduled_date' => $temporary]);
            }

            foreach ($moves as $id => $date) {
                PoSchedule::query()->whereKey($id)->update(['scheduled_date' => $date]);
            }
        }

        // 4. New columns get a new row.
        $scheduleIds = []; // column index => schedule id

        foreach ($columns as $c => $column) {
            $scheduleIds[$c] = $claimed[$c] ?? $po->schedules()->create(['scheduled_date' => $column['date']])->id;
        }

        // 5. The quantity of every line in every column (zeros included): add, change, never duplicate.
        $cells = $scheduleIds === []
            ? collect()
            : PoScheduleItem::query()
                ->whereIn('po_schedule_id', array_values($scheduleIds))
                ->get()
                ->keyBy(fn (PoScheduleItem $cell) => $cell->po_schedule_id.':'.$cell->po_item_id);

        foreach ($columns as $c => $column) {
            $quantities = array_values((array) ($column['quantities'] ?? []));

            foreach ($lineIds as $i => $lineId) {
                $quantity = (int) ($quantities[$i] ?? 0);
                $key = $scheduleIds[$c].':'.$lineId;
                $cell = $cells->get($key);

                if ($cell === null) {
                    PoScheduleItem::query()->create([
                        'po_schedule_id' => $scheduleIds[$c],
                        'po_item_id' => $lineId,
                        'quantity' => $quantity,
                    ]);
                } elseif ($cell->quantity !== $quantity) {
                    $cell->update(['quantity' => $quantity]);
                }

                $cells->forget($key);
            }
        }

        // Whatever is left belongs to a line that no longer exists.
        foreach ($cells as $orphan) {
            $orphan->delete();
        }

        // 6. The first column's quantity (null when the PO has no delivery term).
        $stored = PoItem::query()->where('purchase_order_id', $po->id)->get(['id', 'term_quantity'])->keyBy('id');

        foreach ($lineIds as $i => $lineId) {
            $value = $hasTerm ? (int) ($lines[$i]['term_quantity'] ?? 0) : null;

            if ($stored->get($lineId)?->term_quantity !== $value) {
                PoItem::query()->whereKey($lineId)->update(['term_quantity' => $value]);
            }
        }
    }

    /**
     * Removes the whole split of a PO (every quantity and every term quantity). The dates stay.
     */
    public static function clear(PurchaseOrder $po): void
    {
        $scheduleIds = PoSchedule::query()->where('purchase_order_id', $po->id)->pluck('id')->all();

        if ($scheduleIds !== []) {
            PoScheduleItem::query()->whereIn('po_schedule_id', $scheduleIds)->delete();
        }

        PoItem::query()
            ->where('purchase_order_id', $po->id)
            ->whereNotNull('term_quantity')
            ->update(['term_quantity' => null]);
    }

    /**
     * What the audit log compares: for every line, one readable text of its split (empty = no split).
     * Read straight from the database, so it does not depend on which relations are loaded.
     *
     * @return array<int, array{description: string, text: string}> keyed by line id
     */
    public static function snapshot(int $purchaseOrderId): array
    {
        $lines = PoItem::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->orderBy('id')
            ->get(['id', 'description', 'term_quantity']);

        $schedules = PoSchedule::query()
            ->where('purchase_order_id', $purchaseOrderId)
            ->orderBy('scheduled_date')
            ->get(['id', 'scheduled_date']);

        $cells = $schedules->isEmpty()
            ? collect()
            : PoScheduleItem::query()
                ->whereIn('po_schedule_id', $schedules->pluck('id')->all())
                ->get(['po_schedule_id', 'po_item_id', 'quantity']);

        $byLine = $cells->groupBy('po_item_id');
        $out = [];

        foreach ($lines as $line) {
            $parts = [];

            if ($line->term_quantity !== null) {
                $parts[] = 'within term '.$line->term_quantity;
            }

            $mine = $byLine->get($line->id)?->keyBy('po_schedule_id');

            foreach ($schedules as $schedule) {
                $cell = $mine?->get($schedule->id);

                if ($cell !== null) {
                    $parts[] = $schedule->scheduled_date->toDateString().' '.$cell->quantity;
                }
            }

            $out[$line->id] = ['description' => $line->description, 'text' => implode(', ', $parts)];
        }

        return $out;
    }

    /**
     * The lines whose split changed between two snapshots, as old and new text for the audit log.
     * Null when nothing changed.
     *
     * @param  array<int, array{description: string, text: string}>  $before
     * @param  array<int, array{description: string, text: string}>  $after
     * @return array{old: string, new: string}|null
     */
    public static function diff(array $before, array $after): ?array
    {
        $old = [];
        $new = [];

        foreach ($after as $id => $line) {
            $was = $before[$id]['text'] ?? '';

            if ($was === $line['text']) {
                continue;
            }

            $old[] = $line['description'].': '.($was === '' ? 'none' : $was);
            $new[] = $line['description'].': '.($line['text'] === '' ? 'none' : $line['text']);
        }

        return $new === [] ? null : ['old' => implode(' | ', $old), 'new' => implode(' | ', $new)];
    }

    /**
     * The delivery term column exists only when the PO has a delivery term.
     *
     * @param  array<string, mixed>  $data
     */
    private static function hasTerm(array $data): bool
    {
        return (int) ($data['delivery_term_days'] ?? 0) > 0;
    }
}
