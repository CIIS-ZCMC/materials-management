<?php

namespace App\Services;

use App\Contracts\DeliveryRepositoryInterface;
use App\Contracts\PersonnelDirectory;
use App\Data\PersonData;
use App\Events\DeliveryIarWindowExceeded;
use App\Enums\DeliverySignatoryRole;
use App\Enums\DeliveryStatus;
use App\Enums\IarNumberStatus;
use App\Enums\IarSeriesType;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Delivery;
use App\Models\DeliverySignatory;
use App\Models\IarNumber;
use App\Models\PurchaseOrder;
use App\Policies\DeliveryPolicy;
use App\Support\Audit;
use App\Support\DeliveryRule;
use App\Support\DisplayTime;
use App\Support\DueDate;
use App\Support\Money;
use App\Support\StatusTransition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
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
 *              reserve the IAR number through IarNumberService, save the signatories, audit.
 *              Any failure (a duplicate or voided IAR number, a person not in the directory) rolls back everything.
 *   update()   invoice, fund cluster, amount, date received and signatories. The IAR number and series are locked.
 *   void()     reason required. Status through StatusTransition, the IAR number voided with the same reason.
 *              Step 5.3: the delivery's files are voided with the same reason and leave the combined PDF
 *              (the controller rebuilds it afterwards).
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
        private readonly PersonnelDirectory $people,
        private readonly PoDocumentService $documents,
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
        return $this->deliveries->paginateFiltered($filters, $perPage)
            ->through(fn (Delivery $delivery) => $this->row($delivery));
    }

    /**
     * Drives the Deliveries tab of the PO page: the rows and the "Record delivery" button.
     *
     * @return array{can_record: bool, blocked_reason: string|null, rows: list<array<string, mixed>>}
     */
    public function panel(PurchaseOrder $po): array
    {
        $user = Auth::user();
        $create = Gate::inspect('create', [Delivery::class, $po]);
        $canView = Gate::allows('viewAny', Delivery::class);

        return [
            'can_record' => $create->allowed(),
            // Only people who could record get told why they cannot.
            'blocked_reason' => (! $create->allowed() && $user?->can(DeliveryPolicy::ENCODE)) ? $create->message() : null,
            'rows' => $canView
                ? $this->deliveries->forPurchaseOrder($po->id)->map(fn (Delivery $d) => $this->row($d))->values()->all()
                : [],
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
     * @param  array<string, mixed>  $data  invoice_no, iar_series, iar_number, fund_cluster, amount, date_received, inspectors[], end_user
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

            $rows = $this->resolveSignatories($input['inspectors'], $input['end_user'], collect());

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

            $this->writeSignatories($delivery, $rows);

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
     * @param  array<string, mixed>  $data  invoice_no, fund_cluster, amount, date_received, inspectors[], end_user
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

            $existing = $row->signatories()->get()->keyBy(fn (DeliverySignatory $s) => $s->role->value.'|'.$s->person_id);
            $rows = $this->resolveSignatories($input['inspectors'], $input['end_user'], $existing);

            $before = $this->snapshot($row);

            $row->fill([
                'invoice_no' => $input['invoice_no'],
                'fund_cluster' => $input['fund_cluster'],
                'amount_cents' => $input['amount_cents'],
                'date_received' => $input['date_received'],
            ])->save();

            $this->writeSignatories($row, $rows);

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
     * @return array{invoice_no: string, fund_cluster: string, amount_cents: int, date_received: string, inspectors: list<string>, end_user: string, series: IarSeriesType|null, number: string}
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

        $inspectors = array_values(array_unique(array_filter(
            array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : '', (array) ($data['inspectors'] ?? [])),
            fn (string $value) => $value !== '',
        )));

        if (count($inspectors) < $this->minInspectors() || count($inspectors) > $this->maxInspectors()) {
            $errors['inspectors'] = "Choose {$this->minInspectors()} to {$this->maxInspectors()} inspectors.";
        }

        $endUser = $text('end_user');
        if ($endUser === '') {
            $errors['end_user'] = 'Choose the end-user.';
        }

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
     * Turns the chosen ids into rows to save. A person who is already on this delivery keeps their
     * snapshot (so a past signatory who later became inactive stays); a NEW person must be an active
     * person of that role in the directory.
     *
     * @param  list<string>  $inspectorIds
     * @param  Collection<string, DeliverySignatory>  $existing  keyed "role|person_id"
     * @return list<array{role: DeliverySignatoryRole, person_id: string, employee_no: string, name: string, position: string|null, sort_order: int}>
     *
     * @throws ValidationException
     */
    private function resolveSignatories(array $inspectorIds, string $endUserId, Collection $existing): array
    {
        $wanted = [];

        foreach ($inspectorIds as $index => $id) {
            $wanted[] = [DeliverySignatoryRole::Inspector, $id, "inspectors.{$index}"];
        }

        $wanted[] = [DeliverySignatoryRole::EndUser, $endUserId, 'end_user'];

        $errors = [];
        $rows = [];
        $position = [];

        foreach ($wanted as [$role, $id, $key]) {
            $order = $position[$role->value] = ($position[$role->value] ?? -1) + 1;
            $kept = $existing->get($role->value.'|'.$id);

            if ($kept !== null) {
                $rows[] = [
                    'role' => $role,
                    'person_id' => $id,
                    'employee_no' => $kept->employee_no,
                    'name' => $kept->name,
                    'position' => $kept->position,
                    'sort_order' => $order,
                ];

                continue;
            }

            $person = $this->personForRole($id, $role);

            if (is_string($person)) {
                $errors[$key] = $person;

                continue;
            }

            $rows[] = [
                'role' => $role,
                'person_id' => (string) $person->id,
                'employee_no' => $person->employeeNo,
                'name' => $person->name,
                'position' => $person->position,
                'sort_order' => $order,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /**
     * The directory's find() ignores roles, so the role is checked with a search on the employee number
     * (active people of that role only). Returns the person, or a sentence saying why not.
     */
    private function personForRole(string $id, DeliverySignatoryRole $role): PersonData|string
    {
        $person = $this->people->find($id);

        if ($person === null) {
            return 'This person is not in the personnel directory.';
        }

        foreach ($this->people->search($person->employeeNo, $role->directoryRole(), PersonnelDirectory::MAX_LIMIT) as $match) {
            if ((string) $match->id === (string) $person->id) {
                return $person;
            }
        }

        return "{$person->name} is not an active {$role->label()} in the personnel directory.";
    }

    /**
     * Makes the stored signatories equal to $rows: keeps the people already there, adds the new ones,
     * removes the rest.
     *
     * @param  list<array{role: DeliverySignatoryRole, person_id: string, employee_no: string, name: string, position: string|null, sort_order: int}>  $rows
     */
    private function writeSignatories(Delivery $delivery, array $rows): void
    {
        $existing = $delivery->signatories()->get()->keyBy(fn (DeliverySignatory $s) => $s->role->value.'|'.$s->person_id);
        $keep = [];

        foreach ($rows as $row) {
            $key = $row['role']->value.'|'.$row['person_id'];
            $keep[] = $key;

            if ($existing->has($key)) {
                $current = $existing->get($key);

                if ($current->sort_order !== $row['sort_order']) {
                    $current->forceFill(['sort_order' => $row['sort_order']])->save();
                }

                continue;
            }

            $signatory = new DeliverySignatory;
            $signatory->forceFill([
                'delivery_id' => $delivery->id,
                'role' => $row['role'],
                'person_id' => $row['person_id'],
                'employee_no' => $row['employee_no'],
                'name' => $row['name'],
                'position' => $row['position'],
                'sort_order' => $row['sort_order'],
            ])->save();
        }

        foreach ($existing as $key => $signatory) {
            if (! in_array($key, $keep, true)) {
                $signatory->delete();
            }
        }
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

        $names = fn (DeliverySignatoryRole $role): string => $people
            ->filter(fn (DeliverySignatory $s) => $s->role === $role)
            ->pluck('name')
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