<?php

namespace App\Services;

use App\Contracts\PurchaseOrderRepositoryInterface;
use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Models\PoItem;
use App\Models\PoSchedule;
use App\Models\PurchaseOrder;
use App\Support\Audit;
use App\Support\Money;
use App\Support\PoEditRule;
use App\Support\ScheduleSplit;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    /**
     * The only PO columns a user types (status, due_date, closed_at, source are never typed).
     * pr_no, caf_no, mode_of_procurement and remarks are the print fields of step 4.5.
     */
    private const HEADER_FIELDS = [
        'po_number', 'supplier_id', 'po_date', 'emailed_date', 'delivery_term_days',
        'pr_no', 'caf_no', 'mode_of_procurement', 'remarks',
    ];

    /** Line columns typed per line and printed on the forms (step 4.5). */
    private const LINE_PRINT_FIELDS = ['stock_no', 'brand', 'manufacturer', 'country_of_origin', 'specifications'];

    private const LINE_LABELS = [
        'description' => 'description',
        'item' => 'catalog item',
        'category' => 'category',
        'unit' => 'unit',
        'unit_price' => 'unit price',
        'quantity_ordered' => 'quantity',
        'stock_no' => 'stock no.',
        'brand' => 'brand',
        'manufacturer' => 'manufacturer',
        'country_of_origin' => 'country of origin',
        'specifications' => 'specifications',
    ];

    public function __construct(
        private readonly PurchaseOrderRepositoryInterface $orders,
    ) {}

    /**
     * Turns the raw query string into safe filter values. Anything invalid is
     * ignored (never an error page); the controller sends the result back to the
     * page so the inputs always show what was really applied.
     *
     * @param  array<string, mixed>  $input
     * @return array{search: string, status: string, supplier: string, source: string, from: string, to: string, view: string, sort: string, direction: string}
     */
    public function cleanFilters(array $input): array
    {
        $text = fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        [$sort, $direction] = $this->orders->resolveSort(
            $text('sort') !== '' ? $text('sort') : null,
            $text('direction') !== '' ? $text('direction') : null,
        );

        return [
            'search' => $text('search'),
            'status' => PurchaseOrderStatus::tryFrom($text('status'))?->value ?? '',
            'supplier' => ctype_digit($text('supplier')) ? $text('supplier') : '',
            'source' => PurchaseOrderSource::tryFrom($text('source'))?->value ?? '',
            'from' => $this->validDate($text('from')),
            'to' => $this->validDate($text('to')),
            'view' => $text('view') === 'closed' ? 'closed' : 'active',
            'sort' => $sort,
            'direction' => $direction,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  cleaned by cleanFilters()
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->orders->paginateFiltered($filters, $perPage);
    }

    /**
     * @return list<array{id: int, name: string, is_active: bool}>
     */
    public function supplierOptions(): array
    {
        return $this->orders->supplierOptions()
            ->map(fn ($supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'is_active' => $supplier->is_active,
            ])
            ->values()
            ->all();
    }

    public function detail(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->orders->loadDetail($purchaseOrder);
    }

    public function loadForEdit(PurchaseOrder $purchaseOrder): PurchaseOrder
    {
        return $this->orders->loadForEdit($purchaseOrder);
    }

    /**
     * Suppliers for the form's dropdown (active only, plus the PO's current one).
     *
     * @return list<array{id: int, name: string, is_active: bool}>
     */
    public function supplierChoices(?int $keepSupplierId = null): array
    {
        return $this->orders->supplierChoices($keepSupplierId)
            ->map(fn ($supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'is_active' => $supplier->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * Catalog search for the item box (active items only, at most 10).
     *
     * @return list<array{id: int, name: string, category_id: int, category: string, unit_id: int, unit: string}>
     */
    public function searchItems(string $term): array
    {
        return $this->orders->searchActiveItems($term, 10)
            ->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category_id' => $item->category_id,
                'category' => $item->category->name,
                'unit_id' => $item->unit_id,
                'unit' => $item->unit->name,
            ])
            ->values()
            ->all();
    }

    /**
     * New PO: status draft, source manual, external_id null (model defaults).
     * The due date comes only from recalculateDueDate().
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $this->assertDatesFitQuantity($data);
            ScheduleSplit::check($data);

            $po = new PurchaseOrder(Arr::only($data, self::HEADER_FIELDS));
            $po->recalculateDueDate();
            $po->save();

            $lineIds = $this->syncLines($po, $data['items'], new EloquentCollection);
            $this->syncDeliverySchedule($po, $data, $lineIds, []);

            $po->load([
                'supplier:id,name',
                'items.category:id,name',
                'items.unit:id,name',
                'schedules',
            ]);

            $new = array_filter($this->header($po), fn ($value) => $value !== null);
            $new['items'] = $this->linesSummary($po->items->count(), $this->total($po->items));

            $dates = $this->dates($po);

            if ($dates !== []) {
                $new['schedule'] = implode(', ', $dates);
            }

            $quantities = ScheduleSplit::diff([], ScheduleSplit::snapshot($po->id));

            if ($quantities !== null) {
                $new['quantities'] = $quantities['new'];
            }

            Audit::log('created', $po, "Created PO {$po->auditLabel()}", new: $new, log: 'po');

            return $po;
        });
    }

    /**
     * Saves the header, the lines and the schedule dates in one transaction.
     * Removed lines and dates are deleted. Nothing changed means no audit entry.
     *
     * @param  array<string, mixed>  $data  validated input
     *
     * @throws ValidationException when the PO became locked while the form was open
     */
    public function update(PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($purchaseOrder, $data) {
            $po = $this->orders->findLocked($purchaseOrder->id);

            if (($reason = PoEditRule::blockReason($po)) !== null) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $this->assertDatesFitQuantity($data);
            ScheduleSplit::check($data);

            $oldHeader = $this->header($po);
            $oldLines = $this->lineSnapshots($po->items);
            $oldCount = $po->items->count();
            $oldTotal = $this->total($po->items);
            $oldDates = $this->dates($po);
            $oldSplit = ScheduleSplit::snapshot($po->id);

            $po->fill(Arr::only($data, self::HEADER_FIELDS));

            if ($po->isDirty(['emailed_date', 'delivery_term_days'])) {
                $po->recalculateDueDate(); // includes every approved extension
            }

            $po->save();

            $lineIds = $this->syncLines($po, $data['items'], $po->items);
            $this->syncDeliverySchedule($po, $data, $lineIds, $oldDates);

            $po->load([
                'supplier:id,name',
                'items' => fn ($query) => $query->orderBy('id'),
                'items.item:id,name',
                'items.category:id,name',
                'items.unit:id,name',
                'schedules',
            ]);

            $old = [];
            $new = [];

            $newHeader = $this->header($po);

            foreach ($newHeader as $field => $value) {
                if ($oldHeader[$field] !== $value) {
                    $old[$field] = $oldHeader[$field] ?? '—';
                    $new[$field] = $value ?? '—';
                }
            }

            [$added, $removed, $changed] = $this->diffLines($oldLines, $this->lineSnapshots($po->items));

            $itemParts = array_values(array_filter([
                $added !== [] ? 'Added: '.implode('; ', $added) : null,
                $removed !== [] ? 'Removed: '.implode('; ', $removed) : null,
                $changed !== [] ? 'Changed: '.implode('; ', $changed) : null,
            ]));

            if ($itemParts !== []) {
                $old['items'] = $this->linesSummary($oldCount, $oldTotal);
                $new['items'] = implode(' | ', $itemParts)
                    .' (now '.$this->linesSummary($po->items->count(), $this->total($po->items)).')';
            }

            $newDates = $this->dates($po);
            $addedDates = array_values(array_diff($newDates, $oldDates));
            $removedDates = array_values(array_diff($oldDates, $newDates));

            if ($addedDates !== [] || $removedDates !== []) {
                $old['schedule'] = $oldDates === [] ? 'None' : implode(', ', $oldDates);
                $new['schedule'] = implode(' | ', array_filter([
                    $addedDates !== [] ? 'Added: '.implode(', ', $addedDates) : null,
                    $removedDates !== [] ? 'Removed: '.implode(', ', $removedDates) : null,
                ]));
            }

            // Step 3.3c: the quantity of each line per delivery column (only the lines that changed).
            $quantities = ScheduleSplit::diff($oldSplit, ScheduleSplit::snapshot($po->id));

            if ($quantities !== null) {
                $old['quantities'] = $quantities['old'];
                $new['quantities'] = $quantities['new'];
            }

            if ($old !== [] || $new !== []) {
                $po->touch();

                Audit::log('updated', $po, "Edited PO {$po->auditLabel()}", old: $old, new: $new, log: 'po');
            }

            return $po;
        });
    }

    /**
     * Creates new lines, updates existing ones, deletes the ones that were removed.
     * Category and unit follow the catalog item whenever a line gets a (new) catalog item;
     * an unchanged line keeps its stored snapshot.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  EloquentCollection<int, PoItem>  $existing
     * @return list<int> the ids of the saved lines, in the same order as $lines (step 3.3c links quantities by position)
     */
    private function syncLines(PurchaseOrder $po, array $lines, EloquentCollection $existing): array
    {
        $catalog = $this->orders->catalogItemsByIds(
            collect($lines)->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
        );

        $byId = $existing->keyBy('id');
        $keep = [];

        foreach ($lines as $line) {
            $lineId = ($line['id'] ?? null) !== null ? (int) $line['id'] : null;

            if ($lineId !== null) {
                $row = $byId->get($lineId);

                if ($row === null) {
                    throw ValidationException::withMessages([
                        'form' => 'A line no longer belongs to this PO. Reload the page and try again.',
                    ]);
                }

                $row->fill($this->lineValues($line, $catalog, $row))->save();
                $keep[] = $row->id;
            } else {
                $keep[] = $po->items()->create($this->lineValues($line, $catalog, null))->id;
            }
        }

        $po->items()->whereNotIn('id', $keep)->delete();

        return $keep;
    }

    /**
     * Print fields are written only when the line carries the key, so a request that
     * leaves them out never wipes stored values. When a line gets a (new) catalog item
     * and has no stock number typed, the catalog stock number is copied (a snapshot).
     *
     * @param  array<string, mixed>  $line
     * @param  Collection<int, \App\Models\Item>  $catalog
     * @return array<string, mixed>
     */
    private function lineValues(array $line, Collection $catalog, ?PoItem $row): array
    {
        $values = [
            'description' => trim((string) $line['description']),
            'unit_price' => $line['unit_price'],
            'quantity_ordered' => (int) $line['quantity_ordered'],
        ];

        foreach (self::LINE_PRINT_FIELDS as $field) {
            if (array_key_exists($field, $line)) {
                $values[$field] = filled($line[$field]) ? trim((string) $line[$field]) : null;
            }
        }

        $itemId = ($line['item_id'] ?? null) !== null ? (int) $line['item_id'] : null;

        if ($itemId !== null && ($row === null || $row->item_id !== $itemId)) {
            $item = $catalog->get($itemId);

            $values['item_id'] = $itemId;
            $values['category_id'] = $item->category_id;
            $values['unit_id'] = $item->unit_id;
            $values['stock_no'] ??= $item->stock_no;
        }

        return $values;
    }

    /**
     * Saves the delivery dates. With the split (step 3.3c: the request carries "schedule_columns") the
     * dates and the quantity of each line per date are saved together by ScheduleSplit. Without it the
     * flat "schedule_dates" are saved as before, and any stored split is removed so quantities that no
     * longer add up cannot stay behind.
     *
     * @param  array<string, mixed>  $data  validated input
     * @param  list<int>  $lineIds  ids of the saved lines, in the order of $data['items']
     * @param  list<string>  $existingDates  the PO's dates before this save (Y-m-d)
     */
    private function syncDeliverySchedule(PurchaseOrder $po, array $data, array $lineIds, array $existingDates): void
    {
        if (ScheduleSplit::isActive($data)) {
            ScheduleSplit::sync($po, $data, $lineIds);

            return;
        }

        ScheduleSplit::clear($po);
        $this->syncSchedules($po, $data['schedule_dates'] ?? [], $existingDates);
    }

    /**
     * @param  list<string>  $dates
     * @param  list<string>  $existing  the PO's current dates (Y-m-d)
     */
    private function syncSchedules(PurchaseOrder $po, array $dates, array $existing): void
    {
        $wanted = collect($dates)->unique()->sort()->values()->all();

        $gone = array_values(array_diff($existing, $wanted));

        if ($gone !== []) {
            PoSchedule::query()
                ->where('purchase_order_id', $po->id)
                ->whereIn('scheduled_date', $gone)
                ->delete();
        }

        foreach (array_diff($wanted, $existing) as $date) {
            $po->schedules()->create(['scheduled_date' => $date]);
        }
    }

    /**
     * What the audit log compares: plain values, supplier shown by name.
     *
     * @return array<string, string|int|null>
     */
    private function header(PurchaseOrder $po): array
    {
        return [
            'po_number' => $po->po_number,
            'supplier' => $po->supplier->name,
            'po_date' => $po->po_date?->toDateString(),
            'emailed_date' => $po->emailed_date?->toDateString(),
            'delivery_term_days' => $po->delivery_term_days,
            'due_date' => $po->due_date?->toDateString(),
            'pr_no' => $po->pr_no,
            'caf_no' => $po->caf_no,
            'mode_of_procurement' => $po->mode_of_procurement,
            'remarks' => $po->remarks,
        ];
    }

    /**
     * @return list<string> sorted Y-m-d
     */
    private function dates(PurchaseOrder $po): array
    {
        return $po->schedules->map(fn ($s) => $s->scheduled_date->toDateString())->sort()->values()->all();
    }

    /**
     * @param  EloquentCollection<int, PoItem>  $lines
     * @return Collection<int, array<string, string|int>> keyed by line id
     */
    private function lineSnapshots(EloquentCollection $lines): Collection
    {
        return $lines->mapWithKeys(fn (PoItem $line) => [
            $line->id => [
                'description' => $line->description,
                'item' => $line->item?->name ?? 'not in catalog',
                'category' => $line->category->name,
                'unit' => $line->unit->name,
                'unit_price' => Money::format(Money::cents($line->unit_price)),
                'quantity_ordered' => (int) $line->quantity_ordered,
                'stock_no' => $line->stock_no ?? '—',
                'brand' => $line->brand ?? '—',
                'manufacturer' => $line->manufacturer ?? '—',
                'country_of_origin' => $line->country_of_origin ?? '—',
                'specifications' => $line->specifications ?? '—',
            ],
        ]);
    }

    /**
     * @param  Collection<int, array<string, string|int>>  $before
     * @param  Collection<int, array<string, string|int>>  $after
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} added, removed, changed
     */
    private function diffLines(Collection $before, Collection $after): array
    {
        $added = $after->diffKeys($before)->pluck('description')->values()->all();
        $removed = $before->diffKeys($after)->pluck('description')->values()->all();
        $changed = [];

        foreach ($after->intersectByKeys($before) as $id => $new) {
            $parts = [];

            foreach (self::LINE_LABELS as $field => $label) {
                if ($before[$id][$field] !== $new[$field]) {
                    $parts[] = "{$label} {$before[$id][$field]} to {$new[$field]}";
                }
            }

            if ($parts !== []) {
                $changed[] = $new['description'].': '.implode(', ', $parts);
            }
        }

        return [$added, $removed, $changed];
    }

    /**
     * Total in whole centavos.
     *
     * @param  EloquentCollection<int, PoItem>  $lines
     */
    private function total(EloquentCollection $lines): int
    {
        return (int) $lines->sum(fn (PoItem $line) => Money::cents($line->unit_price) * $line->quantity_ordered);
    }

    private function linesSummary(int $count, int $totalCents): string
    {
        return $count.' '.($count === 1 ? 'line' : 'lines').', '.Money::php($totalCents);
    }

    /**
     * Every schedule date delivers at least one unit, so a PO cannot have more
     * dates than units ordered (sum of quantity_ordered over all lines).
     *
     * @param  array<string, mixed>  $data  validated input
     *
     * @throws ValidationException
     */
    private function assertDatesFitQuantity(array $data): void
    {
        $dates = collect($data['schedule_dates'] ?? [])->filter(fn ($date) => filled($date))->unique()->count();
        $units = (int) collect($data['items'] ?? [])->sum(fn ($line) => (int) ($line['quantity_ordered'] ?? 0));

        if ($dates > $units) {
            $dateWord = $dates === 1 ? 'date' : 'dates';
            $unitWord = $units === 1 ? 'unit' : 'units';

            throw ValidationException::withMessages([
                'schedule_dates' => "{$dates} delivery {$dateWord} for {$units} {$unitWord} ordered. A PO can have at most one date per unit ordered. Remove dates or increase the quantity.",
            ]);
        }
    }

    /** Returns the date if it is a real Y-m-d date, otherwise an empty string. */
    private function validDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }
}