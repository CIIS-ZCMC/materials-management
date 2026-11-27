<?php

namespace App\Services;

use App\Contracts\DeliveryRepositoryInterface;
use App\Events\DeliveryIarWindowExceeded;
use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryProgress;
use App\Enums\DeliverySignatoryRole;
use App\Enums\DeliveryStatus;
use App\Enums\IarNumberStatus;
use App\Enums\IarSeriesType;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliverySignatory;
use App\Models\IarNumber;
use App\Models\PurchaseOrder;
use App\Policies\DeliveryPolicy;
use App\Support\Audit;
use App\Support\DeliveryAmounts;
use App\Support\DeliveryRule;
use App\Support\DisplayTime;
use App\Support\DueDate;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\StatusTransition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * Delivery batches (step 5.2, CONTEXT.md decision 47). One delivery = one IAR. Header only.
 *
 *   create()   one transaction: lock the PO, check the rule, insert the delivery (status for_uploading),
 *              reserve the IAR number through IarNumberService, save the typed signatories, audit.
 *              Any failure (a duplicate or voided IAR number, a bad typed name) rolls back everything.
 *   update()   invoice, fund cluster, amount, date received and signatories. The IAR number and series are locked.
 *
 * Step 5.2b (decision 58): the inspectors and the end-user are TYPED BY HAND (name + optional position). This class no
 * longer reads the personnel directory; person_id and employee_no stay null on new rows (old rows keep theirs until the
 * delivery is edited, which replaces them with the typed rows).
 *   void()     reason required. Status through StatusTransition, the IAR number voided with the same reason.
 *              Step 5.3: the delivery's files are voided with the same reason and leave the combined PDF
 *              (the controller rebuilds it afterwards).
 *              Step 6.1: the delivery's delivered-item rows are voided with the same reason (DeliveryItemService),
 *              so they drop out of every total.
 *   noteIarWindowExceeded()  step 5.3: ONE audit event per delivery when the IAR window has passed.
 *
 * Errors that belong to a field use that field's key; a rule that blocks the whole action uses "form".
 * The audit log is "delivery". Permissions are checked by DeliveryPolicy, not here.
 */
class DeliveryService
{
    public function __construct(
        private readonly DeliveryRepositoryInterface $deliveries,
        private readonly IarNumberService $iar,
        private readonly PoDocumentService $documents,
        private readonly DeliveryItemService $items,
    ) {}

    // ---- reading ---------------------------------------------------------------------------------

    /**
     * Turns the raw query string into safe filter values; anything invalid is ignored.
     *
     * @param  array<string, mixed>  $input
     * @return array{search: string, status: string, sort: string, direction: string}
     */
    public function cleanFilters(array $input): array
    {
        $text = fn (string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';

        $sort = $text('sort');

        return [
            'search' => mb_substr($text('search'), 0, 100),
            'status' => DeliveryStatus::tryFrom($text('status'))?->value ?? '',
            'sort' => in_array($sort, DeliveryRepositoryInterface::SORTABLE, true) ? $sort : 'date_received',
            'direction' => $text('direction') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, string>  $filters  from cleanFilters()
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $page = $this->deliveries->paginateFiltered($filters, $perPage);

        // One query for the whole page: what each delivery carried (no query per row).
        $carried = $this->carriedItems($page->getCollection()->pluck('id')->all());

        return $page->through(fn (Delivery $delivery) => [
            ...$this->row($delivery),
            'delivered_items' => $carried[$delivery->id] ?? [],
        ]);
    }

    /**
     * The ACTIVE delivered-item rows of the given deliveries, for the "Quantity delivered" column of the deliveries list.
     * A voided row does not count (and a voided delivery has none active), the same as every total.
     *
     * @param  list<int>  $deliveryIds
     * @return array<int, list<array{id: int, quantity: string, unit: string|null, description: string|null}>>
     */
    private function carriedItems(array $deliveryIds): array
    {
        if ($deliveryIds === []) {
            return [];
        }

        return DeliveryItem::query()
            ->with('poItem.unit:id,name')
            ->whereIn('delivery_id', $deliveryIds)
            ->where('status', DeliveryItemStatus::Active->value)
            ->orderBy('delivered_on')
            ->orderBy('id')
            ->get()
            ->groupBy('delivery_id')
            ->map(fn ($rows) => $rows->map(fn ($row): array => [
                'id' => $row->id,
                'quantity' => Quantity::format(Quantity::thousandths($row->quantity)),
                'unit' => $row->poItem?->unit?->name,
                'description' => $row->poItem?->description,
            ])->values()->all())
            ->all();
    }

    /**
     * Drives the Deliveries tab of the PO page: the rows and the "Record delivery" button. Step 6.1 adds the derived
     * delivery progress and every PO line with its totals (computed now, never stored). Step 6.1b adds the history grouped by
     * delivery (one IAR each, with the running total of the line after each row).
     *
     * @return array{can_record: bool, blocked_reason: string|null, fully_delivered: bool, rows: list<array<string, mixed>>, progress: array{value: string, label: string}|null, lines: list<array<string, mixed>>, groups: list<array<string, mixed>>, amounts: array<string, mixed>|null}
     */
    public function panel(PurchaseOrder $po): array
    {
        $create = Gate::inspect('create', [Delivery::class, $po]);
        $canView = Gate::allows('viewAny', Delivery::class);
        $totals = $canView ? $this->items->poSummary($po) : null;

        return [
            'can_record' => $create->allowed(),
            // Only people who could record get told why they cannot.
            'blocked_reason' => (! $create->allowed() && Gate::allows(DeliveryPolicy::ENCODE)) ? $create->message() : null,
            // Step 6.1e: the PO is fully delivered (derived). Taken from the progress already worked out above: no extra query.
            // The page softens the "Record delivery" button and adds a note when this is true and the button is shown.
            'fully_delivered' => ($totals['progress']['value'] ?? null) === DeliveryProgress::Full->value,
            'rows' => $canView
                ? $this->deliveries->forPurchaseOrder($po->id)->map(fn (Delivery $d) => $this->row($d))->values()->all()
                : [],
            // Step 6.1: only people who may read deliveries get the totals and the history.
            'progress' => $totals['progress'] ?? null,
            'lines' => $totals['lines'] ?? [],
            'groups' => $totals['groups'] ?? [],
            // Step 6.1d: typed Amounts of the deliveries that are not voided, next to the PO total (a check, never a block).
            'amounts' => $canView ? DeliveryAmounts::forPo($po->id) : null,
        ];
    }

    /**
     * One delivery for the list and the PO tab.
     *
     * @return array<string, mixed>
     */
    public function row(Delivery $delivery): array
    {
        $delivery->loadMissing(['purchaseOrder.supplier', 'signatories']);

        $deadline = $this->iarDeadline($delivery);

        // Step 5.3: written once per delivery, the first time it is loaded after the window passed (log only).
        $this->noteIarWindowExceeded($delivery);

        return [
            'id' => $delivery->id,
            'iar_number' => $delivery->iar_number,
            'iar_series' => ['value' => $delivery->iar_series->value, 'label' => $delivery->iar_series->label()],
            'invoice_no' => $delivery->invoice_no,
            'fund_cluster' => $delivery->fund_cluster,
            'amount' => Money::format($delivery->amount_cents),
            'date_received' => $delivery->date_received->toDateString(),
            'iar_deadline' => $deadline,
            // Derived, never stored: the 3-day window has passed and the delivery still waits for its documents.
            'iar_overdue' => $deadline !== null
                && $delivery->status === DeliveryStatus::ForUploading
                && $deadline < $this->today(),
            'status' => ['value' => $delivery->status->value, 'label' => $delivery->status->label()],
            // Drives the Edit button in the lists (the Policy and the Service check again on save).
            'can_edit' => Gate::allows('update', $delivery),
            'inspectors' => $this->namesOf($delivery, DeliverySignatoryRole::Inspector),
            'end_user' => $this->namesOf($delivery, DeliverySignatoryRole::EndUser)[0] ?? null,
            'purchase_order' => [
                'id' => $delivery->purchase_order_id,
                'po_number' => $delivery->purchaseOrder->po_number,
                'supplier' => $delivery->purchaseOrder->supplier?->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Delivery $delivery): array
    {
        $delivery->loadMissing(['purchaseOrder.supplier', 'signatories', 'creator', 'voider']);

        return [
            ...$this->row($delivery),
            'void_reason' => $delivery->void_reason,
            'voided_by' => $delivery->voider?->name,
            'voided_at' => $delivery->voided_at ? DisplayTime::format($delivery->voided_at) : null,
            'created_by' => $delivery->creator?->name,
            'created_at' => $delivery->created_at ? DisplayTime::format($delivery->created_at) : null,
            'signatories' => $delivery->signatories->map(fn (DeliverySignatory $s) => [
                'role' => $s->role->value,
                'role_label' => $s->role->label(),
                'person_id' => $s->person_id,
                'employee_no' => $s->employee_no,
                'name' => $s->name,
                'position' => $s->position,
            ])->values()->all(),
        ];
    }

    /**
     * What the create and edit forms need besides the delivery itself.
     *
     * @return array<string, mixed>
     */
    public function formOptions(): array
    {
        return [
            'series' => array_map(
                fn (IarSeriesType $type) => ['value' => $type->value, 'label' => $type->label()],
                IarSeriesType::cases(),
            ),
            'today' => $this->today(),
            'min_inspectors' => $this->minInspectors(),
            'max_inspectors' => $this->maxInspectors(),
            'iar_days' => (int) config('mms.iar.processing_days', 3),
        ];
    }

    /**
     * The next free IAR number of a series, for the year of the date received. Does not use it up.
     *
     * @return array{series: string, year: int, number: string}
     *
     * @throws ValidationException
     */
    public function suggestIar(string $series, string $date): array
    {
        $type = IarSeriesType::tryFrom($series)
            ?? throw ValidationException::withMessages(['iar_series' => 'Choose the IAR series.']);

        $this->assertDate($date);
        $year = (int) substr($date, 0, 4);

        $suggestion = $this->iar->suggest($type, $year);

        return ['series' => $type->value, 'year' => $year, 'number' => $suggestion->number];
    }

    // ---- writing ---------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  invoice_no, iar_series, iar_number, fund_cluster, amount, date_received, inspectors[{name, position}], end_user {name, position}
     *
     * @throws ValidationException
     */
    public function create(PurchaseOrder $purchaseOrder, array $data): Delivery
    {
        $input = $this->normalize($data, creating: true);

        return DB::transaction(function () use ($purchaseOrder, $input): Delivery {
            $po = PurchaseOrder::query()->whereKey($purchaseOrder->getKey())->lockForUpdate()->firstOrFail();

            if ($reason = DeliveryRule::addBlockReason($po)) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $this->assertDateAllowed($po, $input['date_received']);

            /** @var IarSeriesType $type */
            $type = $input['series'];
            $number = $input['number'];
            $year = (int) substr($input['date_received'], 0, 4);

            $delivery = new Delivery;
            $delivery->fill([
                'purchase_order_id' => $po->id,
                'invoice_no' => $input['invoice_no'],
                'iar_series' => $type,
                'iar_number' => $number,
                'fund_cluster' => $input['fund_cluster'],
                'amount_cents' => $input['amount_cents'],
                'date_received' => $input['date_received'],
            ]);
            $delivery->forceFill(['created_by' => Auth::id()]);

            try {
                $delivery->save();
            } catch (UniqueConstraintViolationException) {
                // The number is already on another delivery. IarNumberService knows whether it is in use
                // or voided and says so; reserve() throws that message.
                $this->iar->reserve($type, $year, $number);

                throw ValidationException::withMessages(['iar_number' => "IAR number {$number} is already in use."]);
            }

            $delivery->setRelation('purchaseOrder', $po);

            // Rejects a malformed, used or voided number; the exception rolls the whole save back.
            $this->iar->reserve($type, $year, $number, $delivery->id);

            $this->writeSignatories($delivery, $input['inspectors'], $input['end_user']);

            Audit::log(
                event: 'created',
                subject: $delivery,
                description: "Recorded delivery {$delivery->iar_number} for PO {$po->po_number}",
                new: $this->snapshot($delivery),
                extra: ['purchase_order_id' => $po->id],
                log: 'delivery',
            );

            return $delivery;
        });
    }

    /**
     * Edits the header and the signatories. The IAR number and series are never changed here.
     *
     * @param  array<string, mixed>  $data  invoice_no, fund_cluster, amount, date_received, inspectors[{name, position}], end_user {name, position}
     *
     * @throws ValidationException
     */
    public function update(Delivery $delivery, array $data): Delivery
    {
        $input = $this->normalize($data, creating: false);

        return DB::transaction(function () use ($delivery, $input): Delivery {
            $row = $this->lockedDelivery($delivery->getKey());

            if ($reason = DeliveryRule::editBlockReason($row)) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $this->assertDateAllowed($row->purchaseOrder, $input['date_received']);

            // The year of the IAR number comes from the date received (decision 46), so it must not drift.
            if (((int) substr($input['date_received'], 0, 4)) % 100 !== $row->iarYearShort()) {
                throw ValidationException::withMessages([
                    'date_received' => "The date received must stay in the same year as IAR number {$row->iar_number}. To use another year, void this delivery and record it again with a new IAR number.",
                ]);
            }

            $before = $this->snapshot($row);

            $row->fill([
                'invoice_no' => $input['invoice_no'],
                'fund_cluster' => $input['fund_cluster'],
                'amount_cents' => $input['amount_cents'],
                'date_received' => $input['date_received'],
            ])->save();

            $this->writeSignatories($row, $input['inspectors'], $input['end_user']);

            $after = $this->snapshot($row);
            $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                Audit::log(
                    event: 'updated',
                    subject: $row,
                    description: "Edited delivery {$row->iar_number}",
                    old: Arr::only($before, $changed),
                    new: Arr::only($after, $changed),
                    extra: ['purchase_order_id' => $row->purchase_order_id],
                    log: 'delivery',
                );
            }

            return $row;
        });
    }

    /**
     * Voids a delivery. The reason is required; the IAR number is voided with the same reason and
     * stays recorded forever (never reused).
     *
     * @throws ValidationException
     */
    public function void(Delivery $delivery, string $reason): Delivery
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of 5 to 500 characters.']);
        }

        return DB::transaction(function () use ($delivery, $reason): Delivery {
            $row = $this->lockedDelivery($delivery->getKey());

            if ($message = DeliveryRule::voidBlockReason($row)) {
                throw ValidationException::withMessages(['form' => $message]);
            }

            try {
                // Moves the status, writes the "status_changed" audit entry (log delivery) with the reason.
                StatusTransition::apply(
                    $row,
                    'status',
                    DeliveryStatus::Voided,
                    reason: $reason,
                    extra: ['iar_number' => $row->iar_number, 'purchase_order_id' => $row->purchase_order_id],
                    log: 'delivery',
                );
            } catch (InvalidStatusTransition $e) {
                throw ValidationException::withMessages(['form' => $e->getMessage()]);
            }

            $row->forceFill([
                'void_reason' => $reason,
                'voided_by' => Auth::id(),
                'voided_at' => now(),
            ])->save();

            // Step 5.3: the files stay on record, marked void with the same reason; they leave the combined PDF.
            if (Auth::user() !== null) {
                $this->documents->voidAllOfDelivery($row, $reason, Auth::user());
            }

            $number = IarNumber::query()->where('delivery_id', $row->id)->first()
                ?? IarNumber::query()->where('number', $row->iar_number)->first()
                ?? throw new LogicException("Delivery {$row->id} has no IAR number record ({$row->iar_number}).");

            if ($number->status === IarNumberStatus::Used) {
                $this->iar->void($number, $reason);
            }

            // Step 6.1: the delivered-item rows stay on record, marked void with the same reason. Every total ignores a
            // voided delivery anyway; this keeps the history readable.
            if (Auth::user() !== null) {
                $this->items->voidAllOfDelivery($row, $reason, Auth::user());
            }

            // TODO(step 7.3 / 8.1): call PurchaseOrderService::syncClosed($row->purchaseOrder) here, because a voided
            // delivery can change "fully delivered" (and with it whether the PO is closed). Not built yet on purpose.

            return $row;
        });
    }

    /**
     * Step 5.3: the IAR window (date received + iar.processing_days) has passed and the delivery still waits for its
     * documents. Writes ONE audit entry "iar_window_exceeded" (log "delivery") and fires DeliveryIarWindowExceeded.
     * LOG ONLY: the consequence is not defined by MMS, and no email is sent. It runs when a delivery is loaded
     * (row(), so every list and page) or changed (the document actions), never on a schedule. The audit table
     * itself is the "already written" memory, so no migration is needed; the delivery row is locked while checking
     * so two requests at the same moment still write one entry.
     */
    public function noteIarWindowExceeded(Delivery $delivery): void
    {
        if ($delivery->status !== DeliveryStatus::ForUploading) {
            return;
        }

        $deadline = $this->iarDeadline($delivery);

        if ($deadline === null || $deadline >= $this->today() || $this->windowLogged($delivery)) {
            return;
        }

        DB::transaction(function () use ($delivery, $deadline): void {
            $row = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->first();

            if ($row === null || $row->status !== DeliveryStatus::ForUploading || $this->windowLogged($row)) {
                return;
            }

            Audit::log(
                event: 'iar_window_exceeded',
                subject: $row,
                description: "IAR window exceeded for delivery {$row->iar_number}: the documents were due by {$deadline}",
                extra: ['deadline' => $deadline, 'date_received' => $row->date_received->toDateString(), 'purchase_order_id' => $row->purchase_order_id],
                log: 'delivery',
            );

            DB::afterCommit(fn () => DeliveryIarWindowExceeded::dispatch($row, $deadline));
        });
    }

    private function windowLogged(Delivery $delivery): bool
    {
        $model = config('activitylog.activity_model', Activity::class);

        return $model::query()
            ->where('log_name', 'delivery')
            ->where('event', 'iar_window_exceeded')
            ->where('subject_type', $delivery->getMorphClass())
            ->where('subject_id', $delivery->getKey())
            ->exists();
    }

    // ---- internals -------------------------------------------------------------------------------

    /** Locks the PO first, then the delivery (same order as create(), so two requests cannot deadlock). */
    private function lockedDelivery(int|string $id): Delivery
    {
        $poId = Delivery::query()->whereKey($id)->value('purchase_order_id');

        $po = PurchaseOrder::query()->whereKey($poId)->lockForUpdate()->firstOrFail();
        $row = $this->deliveries->findForUpdate($id);
        $row->setRelation('purchaseOrder', $po);

        return $row;
    }

    /**
     * Cleans and checks the typed values (the Form Request checks the same things first; this keeps the
     * Service safe if it is ever called from somewhere else).
     *
     * @param  array<string, mixed>  $data
     * @return array{invoice_no: string, fund_cluster: string, amount_cents: int, date_received: string, inspectors: list<array{name: string, position: string|null}>, end_user: array{name: string, position: string|null}, series: IarSeriesType|null, number: string}
     *
     * @throws ValidationException
     */
    private function normalize(array $data, bool $creating): array
    {
        $text = fn (string $key): string => is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
        $errors = [];

        $invoice = $text('invoice_no');
        if ($invoice === '' || mb_strlen($invoice) > 100) {
            $errors['invoice_no'] = 'Enter the invoice number (up to 100 characters).';
        }

        $fund = $text('fund_cluster');
        if ($fund === '' || mb_strlen($fund) > 100) {
            $errors['fund_cluster'] = 'Enter the fund cluster (up to 100 characters).';
        }

        $amount = $text('amount');
        if (! preg_match('/^\d{1,11}(\.\d{1,2})?$/', $amount) || Money::cents($amount) < 1) {
            $errors['amount'] = 'Enter the amount in pesos, more than zero, for example 125000.50.';
        }

        $date = $text('date_received');
        if (! $this->isDate($date)) {
            $errors['date_received'] = 'Enter the date received as YYYY-MM-DD.';
        }

        [$inspectors, $endUser] = $this->typedPeople($data, $errors);

        $series = null;
        $number = '';

        if ($creating) {
            $series = IarSeriesType::tryFrom($text('iar_series'));
            if ($series === null) {
                $errors['iar_series'] = 'Choose the IAR series.';
            }

            $number = strtoupper($text('iar_number'));
            if ($number === '') {
                $errors['iar_number'] = 'Enter the IAR number.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'invoice_no' => $invoice,
            'fund_cluster' => $fund,
            'amount_cents' => Money::cents($amount),
            'date_received' => $date,
            'inspectors' => $inspectors,
            'end_user' => $endUser,
            'series' => $series,
            'number' => $number,
        ];
    }

    /** The date received may not be in the future (Manila date) or before the PO date. */
    private function assertDateAllowed(PurchaseOrder $po, string $date): void
    {
        if ($date > $this->today()) {
            throw ValidationException::withMessages(['date_received' => 'The date received cannot be in the future.']);
        }

        $poDate = $po->po_date?->toDateString();

        if ($poDate !== null && $date < $poDate) {
            throw ValidationException::withMessages(['date_received' => "The date received cannot be before the PO date ({$poDate})."]);
        }
    }

    /**
     * Step 5.2b: cleans and checks the TYPED inspectors and end-user. SaveDeliveryRequest checks the same things first, with
     * the same sentences; this keeps the Service safe if it is called from somewhere else. Trim, collapse repeated spaces,
     * keep the typed case. A row with an empty name AND an empty position is ignored; a position without a name is an
     * error on that row's name. Error keys use the index of the row AS SENT, so the form shows each error under the row that
     * caused it. The same inspector name twice (ignoring case) is refused on the second row; the same person may be
     * inspector and end-user.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors  filled by reference
     * @return array{0: list<array{name: string, position: string|null}>, 1: array{name: string, position: string|null}}
     */
    private function typedPeople(array $data, array &$errors): array
    {
        $inspectors = [];
        $seen = [];

        foreach ((array) ($data['inspectors'] ?? []) as $index => $row) {
            $person = $this->cleanPerson($row);

            if ($person === null) {
                continue;
            }

            $problems = $this->personProblems($person);

            if (isset($problems['name'])) {
                $errors["inspectors.{$index}.name"] = $problems['name'];
            }

            if (isset($problems['position'])) {
                $errors["inspectors.{$index}.position"] = $problems['position'];
            }

            $key = mb_strtolower($person['name']);

            if ($person['name'] !== '' && in_array($key, $seen, true) && ! isset($errors["inspectors.{$index}.name"])) {
                $errors["inspectors.{$index}.name"] = 'The same inspector name is entered twice.';
            }

            $seen[] = $key;
            $inspectors[] = $person;
        }

        if (count($inspectors) < $this->minInspectors()) {
            $errors['inspectors'] = $this->minInspectors() === 1
                ? 'Enter at least one inspector.'
                : "Enter at least {$this->minInspectors()} inspectors.";
        } elseif (count($inspectors) > $this->maxInspectors()) {
            $errors['inspectors'] = "Enter at most {$this->maxInspectors()} inspectors.";
        }

        $endUser = $this->cleanPerson($data['end_user'] ?? null);

        if ($endUser === null) {
            $errors['end_user'] = 'Enter the end-user.';
            $endUser = ['name' => '', 'position' => null];
        } else {
            $problems = $this->personProblems($endUser);

            if (isset($problems['name'])) {
                $errors['end_user.name'] = $problems['name'];
            }

            if (isset($problems['position'])) {
                $errors['end_user.position'] = $problems['position'];
            }
        }

        return [$inspectors, $endUser];
    }

    /**
     * One typed row, or null when it is not an array or both fields are empty (an unused row is harmless).
     *
     * @return array{name: string, position: string|null}|null
     */
    private function cleanPerson(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $text = function (mixed $value): string {
            if (! is_scalar($value)) {
                return '';
            }

            return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value);
        };

        $name = $text($row['name'] ?? null);
        $position = $text($row['position'] ?? null);

        if ($name === '' && $position === '') {
            return null;
        }

        return ['name' => $name, 'position' => $position === '' ? null : $position];
    }

    /**
     * @param  array{name: string, position: string|null}  $person
     * @return array{name?: string, position?: string}
     */
    private function personProblems(array $person): array
    {
        $problems = [];

        if ($person['name'] === '') {
            $problems['name'] = 'Enter the name.';
        } elseif (mb_strlen($person['name']) < 2 || mb_strlen($person['name']) > 150) {
            $problems['name'] = 'The name must be 2 to 150 characters.';
        }

        if ($person['position'] !== null && mb_strlen($person['position']) > 150) {
            $problems['position'] = 'The position can be at most 150 characters.';
        }

        return $problems;
    }

    /**
     * Makes the stored signatories equal to the typed rows: the old rows are deleted and the new ones inserted (nothing
     * references a signatory id). person_id and employee_no stay null: nobody is looked up in a directory.
     *
     * @param  list<array{name: string, position: string|null}>  $inspectors
     * @param  array{name: string, position: string|null}  $endUser
     */
    private function writeSignatories(Delivery $delivery, array $inspectors, array $endUser): void
    {
        $delivery->signatories()->delete();

        $rows = [];

        foreach (array_values($inspectors) as $order => $person) {
            $rows[] = [DeliverySignatoryRole::Inspector, $person, $order];
        }

        $rows[] = [DeliverySignatoryRole::EndUser, $endUser, 0];

        foreach ($rows as [$role, $person, $order]) {
            $signatory = new DeliverySignatory;
            $signatory->forceFill([
                'delivery_id' => $delivery->id,
                'role' => $role,
                'person_id' => null,
                'employee_no' => null,
                'name' => $person['name'],
                'position' => $person['position'],
                'sort_order' => $order,
            ])->save();
        }

        // A relation loaded earlier (row(), detail()) would still hold the old rows.
        $delivery->unsetRelation('signatories');
    }

    /**
     * What the audit log shows (readable text, never ids).
     *
     * @return array<string, string>
     */
    private function snapshot(Delivery $delivery): array
    {
        $delivery->loadMissing('purchaseOrder');
        $people = $delivery->signatories()->get();

        // "Name (Position)", just "Name" without a position, so a position-only change is logged too.
        $names = fn (DeliverySignatoryRole $role): string => $people
            ->filter(fn (DeliverySignatory $s) => $s->role === $role)
            ->sortBy('sort_order')
            ->map(fn (DeliverySignatory $s) => $s->position ? "{$s->name} ({$s->position})" : $s->name)
            ->implode(', ');

        return [
            'purchase_order' => $delivery->purchaseOrder->po_number,
            'invoice_no' => $delivery->invoice_no,
            'iar_series' => $delivery->iar_series->label(),
            'iar_number' => $delivery->iar_number,
            'fund_cluster' => $delivery->fund_cluster,
            'amount' => Money::php($delivery->amount_cents),
            'date_received' => $delivery->date_received->toDateString(),
            'inspectors' => $names(DeliverySignatoryRole::Inspector),
            'end_user' => $names(DeliverySignatoryRole::EndUser),
        ];
    }

    /** @return list<string> */
    private function namesOf(Delivery $delivery, DeliverySignatoryRole $role): array
    {
        return $delivery->signatories
            ->filter(fn (DeliverySignatory $s) => $s->role === $role)
            ->pluck('name')
            ->values()
            ->all();
    }

    /** Date received plus iar.processing_days, counted the way the Delivery Term is (App\Support\DueDate). */
    private function iarDeadline(Delivery $delivery): ?string
    {
        return DueDate::fromTerm($delivery->date_received, (int) config('mms.iar.processing_days', 3))?->toDateString();
    }

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function assertDate(string $value): void
    {
        if (! $this->isDate($value)) {
            throw ValidationException::withMessages(['date_received' => 'Enter the date received as YYYY-MM-DD.']);
        }
    }

    private function today(): string
    {
        return now()->setTimezone((string) config('mms.display_timezone', 'Asia/Manila'))->toDateString();
    }

    private function minInspectors(): int
    {
        return max(1, (int) config('mms.delivery.min_inspectors', 1));
    }

    private function maxInspectors(): int
    {
        return max($this->minInspectors(), (int) config('mms.delivery.max_inspectors', 3));
    }
}