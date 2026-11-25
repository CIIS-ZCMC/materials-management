<?php

namespace App\Services;

use App\Contracts\DeliveryItemRepositoryInterface;
use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\DeliveryPolicy;
use App\Support\Audit;
use App\Support\DeliveryItemRule;
use App\Support\DeliveryTotals;
use App\Support\DisplayTime;
use App\Support\Quantity;
use App\Support\StatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Delivered items and the running totals (step 6.1). One row = the quantity of one PO line delivered on one date,
 * inside one delivery. The PO Reviewer's delivery update.
 *
 *   record()             several lines in ONE save, all or nothing. One transaction: lock the PO row, then the delivery
 *                        row, re-check DeliveryItemRule, check every line (same PO, no active quantity for the line on this
 *                        IAR yet, over-delivery), insert, audit. Step 6.1b: ONE DELIVERY = ONE IAR = ONE QUANTITY PER PO LINE;
 *                        the row's date is the delivery's date received.
 *   void()               ONE row, with a reason (StatusTransition, log "delivery"). A row is never edited.
 *   voidAllOfDelivery()  called by DeliveryService::void(): the rows stay on record, marked void with the delivery's
 *                        reason (ONE audit entry, like the delivery's files in step 5.3).
 *   sheet(), panel(), poSummary(), progress()   what the pages show. Totals come from DeliveryTotals: computed, never stored.
 *
 * Errors that belong to a field use that field's key (lines.0.quantity); a rule that blocks the whole action uses "form".
 * Permissions are the Policy's job. Lock order everywhere: PO row, then delivery row.
 */
class DeliveryItemService
{
    public function __construct(private readonly DeliveryItemRepositoryInterface $items) {}

    // ---- reading ---------------------------------------------------------------------------------

    /**
     * What the "update delivered items" form needs: every line of the PO with its running totals.
     *
     * Step 6.1b: no date per line (the date is the delivery's date received), and a line that already has an active
     * quantity on this IAR says so (entered_here), so the form shows it as taken.
     *
     * @return array{date_received: string, progress: array{value: string, label: string}, lines: list<array<string, mixed>>}
     */
    public function sheet(Delivery $delivery): array
    {
        $delivery->loadMissing('purchaseOrder');
        $po = $delivery->purchaseOrder;

        return [
            'date_received' => $delivery->date_received->toDateString(),
            'progress' => $this->progress($po->id),
            'lines' => $this->lines($po, only: $delivery, withRows: false),
        ];
    }

    /**
     * The delivered-items panel of the delivery page: every PO line with its running totals (all deliveries),
     * and the history rows of THIS delivery. Hiding a button is for convenience; the Policy and the Service decide.
     *
     * @return array<string, mixed>
     */
    public function panel(Delivery $delivery, ?User $user): array
    {
        $delivery->loadMissing('purchaseOrder');

        $update = Gate::inspect('updateItems', $delivery);
        $handles = (bool) $user?->can(DeliveryPolicy::UPDATE_ITEMS);

        // Step 6.1b: more of an item arrives with a NEW delivery and a new IAR. The link is for people who record deliveries
        // (delivery.encode) and only while the PO still accepts one (the same check as the PO page button); the Encoder is told
        // why when it does not, everybody else is told to ask the Encoder.
        $record = Gate::inspect('create', [Delivery::class, $delivery->purchaseOrder]);
        $encodes = (bool) $user?->can(DeliveryPolicy::ENCODE);

        return [
            'can_update' => $update->allowed(),
            // Only people who could act are told why they cannot.
            'locked_reason' => $handles && ! $update->allowed() ? $update->message() : null,
            'can_record_another' => $record->allowed(),
            'record_blocked_reason' => $encodes && ! $record->allowed() ? $record->message() : null,
            'progress' => $this->progress($delivery->purchase_order_id),
            'lines' => $this->lines($delivery->purchaseOrder, only: $delivery),
        ];
    }

    /**
     * For the PO page (Deliveries tab): the derived progress, every line with its totals, and (step 6.1b) the history
     * grouped by delivery, one IAR each, with the running total of each line after each row.
     *
     * @return array{progress: array{value: string, label: string}, lines: list<array<string, mixed>>, groups: list<array<string, mixed>>}
     */
    public function poSummary(PurchaseOrder $po): array
    {
        return [
            'progress' => $this->progress($po->id),
            'lines' => $this->lines($po, withRows: false),
            'groups' => $this->groups($po),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    public function progress(int $purchaseOrderId): array
    {
        $progress = DeliveryTotals::progress($purchaseOrderId);

        return ['value' => $progress->value, 'label' => $progress->label()];
    }

    /**
     * Every line of the PO: ordered, delivered, undelivered, over by (computed now), and its history rows.
     * With $only (the delivery page and the form) it also says what the line has on THIS delivery: entered_here (the sum
     * of its ACTIVE quantities, null when none) and active_here (how many active rows; more than 1 only for rows saved
     * before step 6.1b, which still count).
     *
     * @param  Delivery|null  $only  show only the rows of this delivery (the totals still count every delivery)
     * @param  bool  $withRows  false leaves "rows" empty (the form and the PO tab do not use them)
     * @return list<array<string, mixed>>
     */
    private function lines(PurchaseOrder $po, ?Delivery $only = null, bool $withRows = true): array
    {
        $poItems = PoItem::query()->with('unit:id,name')->where('purchase_order_id', $po->id)->orderBy('id')->get();
        $delivered = DeliveryTotals::deliveredByLine($poItems->pluck('id')->map(fn ($id) => (int) $id)->all());

        $rows = collect();

        // The rows of this delivery are also needed for entered_here, even when the caller does not show them.
        if ($withRows || $only !== null) {
            $rows = ($only !== null ? $this->items->forDelivery($only->id) : $this->items->forPurchaseOrder($po->id))
                ->groupBy('po_item_id');
        }

        /** @var array<int, bool> $voidable delivery id => whether this person may void rows of it */
        $voidable = [];

        return $poItems->map(function (PoItem $line) use ($delivered, $rows, $only, $withRows, &$voidable): array {
            $figures = DeliveryTotals::figures($line->quantity_ordered, $delivered[$line->id] ?? 0);
            $lineRows = collect($rows->get($line->id, []));
            $active = $lineRows->filter(fn (DeliveryItem $row) => $row->status === DeliveryItemStatus::Active);

            return [
                'po_item_id' => $line->id,
                'description' => $line->description,
                'unit' => $line->unit?->name,
                'ordered' => Quantity::format($figures['ordered']),
                'delivered' => Quantity::format($figures['delivered']),
                'undelivered' => Quantity::format($figures['undelivered']),
                'over_by' => $figures['over_by'] > 0 ? Quantity::format($figures['over_by']) : null,
                'entered_here' => $only !== null && $active->isNotEmpty()
                    ? Quantity::format((int) $active->sum(fn (DeliveryItem $row) => Quantity::thousandths($row->quantity)))
                    : null,
                'active_here' => $only !== null ? $active->count() : 0,
                'rows' => $withRows
                    ? $lineRows
                        ->map(function (DeliveryItem $row) use (&$voidable): array {
                            $voidable[$row->delivery_id] ??= Gate::allows('voidItem', $row->delivery);

                            return $this->rowData($row, $voidable[$row->delivery_id]);
                        })
                        ->values()
                        ->all()
                    : [],
            ];
        })->values()->all();
    }

    /**
     * The PO tab history (step 6.1b): one group per delivery (one IAR each), in the order of the date received, with the
     * status of the delivery and, for each row, the running total of its PO line AFTER that row. Display only: the real
     * totals come from DeliveryTotals. A voided row, and every row of a voided delivery, shows no running total (it does
     * not count). A delivery without items yet is listed with no rows.
     *
     * @return list<array<string, mixed>>
     */
    private function groups(PurchaseOrder $po): array
    {
        $poItems = PoItem::query()->with('unit:id,name')->where('purchase_order_id', $po->id)->get()->keyBy('id');

        $deliveries = Delivery::query()
            ->where('purchase_order_id', $po->id)
            ->orderBy('date_received')
            ->orderBy('id')
            ->get(['id', 'iar_number', 'status', 'date_received']);

        $rows = $this->items->forPurchaseOrder($po->id)->groupBy('delivery_id');

        /** @var array<int, int> $running thousandths per PO line, carried from one delivery to the next */
        $running = [];

        return $deliveries->map(function (Delivery $delivery) use ($poItems, $rows, &$running): array {
            $counts = $delivery->status !== DeliveryStatus::Voided;

            return [
                'delivery_id' => $delivery->id,
                'iar_number' => $delivery->iar_number,
                'status' => ['value' => $delivery->status->value, 'label' => $delivery->status->label()],
                'date_received' => $delivery->date_received->toDateString(),
                'rows' => collect($rows->get($delivery->id, []))
                    ->map(function (DeliveryItem $row) use ($poItems, $counts, &$running): array {
                        $line = $poItems->get($row->po_item_id);
                        $running[$row->po_item_id] ??= 0;
                        $total = null;

                        if ($counts && $row->status === DeliveryItemStatus::Active) {
                            $running[$row->po_item_id] += Quantity::thousandths($row->quantity);
                            $total = Quantity::format($running[$row->po_item_id]);
                        }

                        // No Void button on the PO tab: a row is voided on its own delivery page.
                        return [
                            ...$this->rowData($row, false),
                            'description' => $line?->description,
                            'unit' => $line?->unit?->name,
                            'running_total' => $total,
                        ];
                    })
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function rowData(DeliveryItem $row, bool $canVoidInDelivery): array
    {
        return [
            'id' => $row->id,
            'delivery_id' => $row->delivery_id,
            'iar_number' => $row->delivery->iar_number,
            'delivery_voided' => $row->delivery->status === DeliveryStatus::Voided,
            'delivered_on' => $row->delivered_on->toDateString(),
            'quantity' => Quantity::format(Quantity::thousandths($row->quantity)),
            'status' => ['value' => $row->status->value, 'label' => $row->status->label()],
            'over_delivery' => $row->over_delivery,
            'over_delivery_reason' => $row->over_delivery_reason,
            'recorded_by' => $row->recorder?->name,
            'recorded_at' => $row->created_at ? DisplayTime::format($row->created_at) : null,
            'void_reason' => $row->void_reason,
            'voided_by' => $row->voider?->name,
            'voided_at' => $row->voided_at ? DisplayTime::format($row->voided_at) : null,
            // Drives the Void button; the Policy and the Service check again on save.
            'can_void' => $canVoidInDelivery && $row->status === DeliveryItemStatus::Active,
        ];
    }

    // ---- writing ---------------------------------------------------------------------------------

    /**
     * Records several PO lines in one save: the quantity delivered for each. All or nothing. One quantity per PO line per
     * delivery (step 6.1b): a line that already has an ACTIVE row in this delivery is refused. The row's date is the
     * delivery's date received (its own checks, step 5.2: not in the future, not before the PO date, same year as the IAR number).
     *
     * @param  list<array<string, mixed>>  $lines  po_item_id, quantity, over_delivery, over_delivery_reason
     * @return list<DeliveryItem>
     *
     * @throws ValidationException
     */
    public function record(Delivery $delivery, array $lines, User $actor): array
    {
        $input = $this->normalize($lines);

        return DB::transaction(function () use ($delivery, $input, $actor): array {
            [$po, $row] = $this->lock($delivery);

            if ($reason = DeliveryItemRule::addBlockReason($row)) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $ids = array_values(array_unique(array_column($input, 'po_item_id')));

            // Only lines of THIS delivery's PO are found; a line of another PO is refused below.
            $poItems = PoItem::query()->with('unit:id,name')
                ->where('purchase_order_id', $po->id)
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            // Read under the PO lock, so two people cannot both pass the over-delivery check at the same moment.
            $before = DeliveryTotals::deliveredByLine($ids);

            // One quantity per PO line per delivery (step 6.1b), read under the same locks. A voided row does not count.
            $activeIds = DeliveryItemRule::activeLineIds($row);
            $date = $row->date_received->toDateString();

            $errors = [];
            $seen = [];
            $plans = [];

            foreach ($input as $index => $line) {
                $id = $line['po_item_id'];
                $poItem = $poItems->get($id);

                if ($poItem === null) {
                    $errors["lines.{$index}.po_item_id"] = 'This line does not belong to the PO of this delivery.';

                    continue;
                }

                if ($message = DeliveryItemRule::lineAlreadyEnteredReason($id, $activeIds)) {
                    $errors["lines.{$index}.po_item_id"] = $message;

                    continue;
                }

                if (isset($seen[$id])) {
                    $errors["lines.{$index}.po_item_id"] = 'This line is entered twice. Enter it once per save.';

                    continue;
                }

                $seen[$id] = true;

                $orderedK = $poItem->quantity_ordered * Quantity::SCALE;
                $afterK = ($before[$id] ?? 0) + $line['quantity'];
                $over = $afterK > $orderedK;
                $unit = $poItem->unit?->name;

                if ($over && ! $line['over_delivery']) {
                    $errors["lines.{$index}.quantity"] = 'This would deliver '.Quantity::format($afterK).' of '.$poItem->quantity_ordered.($unit ? " {$unit}" : '').' ordered ('.Quantity::format($afterK - $orderedK).' more than ordered). Tick over-delivery and write a reason to record it.';
                } elseif ($over && (mb_strlen($line['reason']) < 5 || mb_strlen($line['reason']) > 500)) {
                    $errors["lines.{$index}.over_delivery_reason"] = 'Give a reason of 5 to 500 characters for the over-delivery.';
                }

                $plans[] = ['poItem' => $poItem, 'line' => $line, 'over' => $over, 'after' => $afterK, 'ordered' => $orderedK, 'unit' => $unit];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $saved = [];
            $texts = [];
            $overTexts = [];

            foreach ($plans as $plan) {
                /** @var PoItem $poItem */
                $poItem = $plan['poItem'];
                $line = $plan['line'];

                $item = new DeliveryItem;
                $item->fill([
                    'delivery_id' => $row->id,
                    'po_item_id' => $poItem->id,
                    'quantity' => Quantity::decimal($line['quantity']),
                    'delivered_on' => $date,
                    'over_delivery' => $plan['over'],
                    // An over-delivery tick on a line that does not go over is ignored: no flag, no reason.
                    'over_delivery_reason' => $plan['over'] ? $line['reason'] : null,
                ]);
                $item->forceFill(['recorded_by' => $actor->id])->save();

                $saved[] = $item;

                $text = $poItem->description.': '.Quantity::format($line['quantity']).($plan['unit'] ? " {$plan['unit']}" : '')
                    .' on '.$date.' (total '.Quantity::format($plan['after']).' of '.Quantity::format($plan['ordered']).')';

                $texts[] = $text;

                if ($plan['over']) {
                    $overTexts[] = "{$text}. Reason: {$line['reason']}";
                }
            }

            Audit::log(
                event: 'delivery_items_recorded',
                subject: $row,
                description: "Recorded delivered items on delivery {$row->iar_number} (PO {$po->po_number}): ".count($saved).' line(s)'.($overTexts !== [] ? ', '.count($overTexts).' over-delivery' : ''),
                new: ['delivered_items' => implode('; ', $texts)],
                extra: ['purchase_order_id' => $po->id, 'over_delivery' => $overTexts],
                causer: $actor,
                log: 'delivery',
            );

            return $saved;
        });
    }

    /**
     * Voids ONE row with a reason. The row stays on record and stops counting. To correct a quantity, void the row
     * and enter the line again.
     *
     * @throws ValidationException error key "reason" or "form"
     */
    public function void(Delivery $delivery, DeliveryItem $item, string $reason, User $actor): DeliveryItem
    {
        $reason = $this->cleanReason($reason);

        return DB::transaction(function () use ($delivery, $item, $reason, $actor): DeliveryItem {
            [$po, $row] = $this->lock($delivery);

            // A row of another delivery is "not found" (404).
            $line = $this->items->findForUpdate($row->id, $item->getKey());

            if ($message = DeliveryItemRule::voidBlockReason($row, $line)) {
                throw ValidationException::withMessages(['form' => $message]);
            }

            $line->setRelation('delivery', $row);
            $line->loadMissing('poItem');

            $quantity = Quantity::thousandths($line->quantity);
            $before = DeliveryTotals::deliveredByLine([$line->po_item_id])[$line->po_item_id] ?? 0;

            try {
                // Moves the status, writes the "status_changed" audit entry (log delivery) with the reason.
                StatusTransition::apply(
                    $line,
                    'status',
                    DeliveryItemStatus::Voided,
                    reason: $reason,
                    extra: [
                        'delivery_id' => $row->id,
                        'purchase_order_id' => $po->id,
                        'iar_number' => $row->iar_number,
                        'item' => $line->poItem->description,
                        'quantity' => Quantity::format($quantity),
                        'delivered_total_after' => Quantity::format(max(0, $before - $quantity)),
                    ],
                    log: 'delivery',
                );
            } catch (InvalidStatusTransition $e) {
                throw ValidationException::withMessages(['form' => $e->getMessage()]);
            }

            $line->forceFill([
                'void_reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
            ])->save();

            return $line;
        });
    }

    /**
     * Called by DeliveryService::void() inside its transaction (the PO row and the delivery row are already locked):
     * every ACTIVE row of the delivery is marked void with the delivery's reason and ONE audit entry is written.
     * The totals would ignore these rows anyway (a voided delivery never counts); marking them keeps the history readable.
     *
     * @return int how many rows were voided
     */
    public function voidAllOfDelivery(Delivery $delivery, string $reason, User $actor): int
    {
        $rows = $this->items->activeForUpdate($delivery->id);

        if ($rows->isEmpty()) {
            return 0;
        }

        DeliveryItem::query()->whereIn('id', $rows->modelKeys())->update([
            'status' => DeliveryItemStatus::Voided->value,
            'void_reason' => "Delivery voided: {$reason}",
            'voided_by' => $actor->id,
            'voided_at' => now(),
        ]);

        Audit::log(
            event: 'delivery_items_voided',
            subject: $delivery,
            description: "Voided {$rows->count()} delivered item line(s) with delivery {$delivery->iar_number}",
            reason: $reason,
            extra: ['purchase_order_id' => $delivery->purchase_order_id, 'count' => $rows->count()],
            causer: $actor,
            log: 'delivery',
        );

        return $rows->count();
    }

    // ---- internals -------------------------------------------------------------------------------

    /**
     * PO row first, then the delivery row, both locked; the PO is attached to the delivery for the rules.
     *
     * @return array{0: PurchaseOrder, 1: Delivery}
     */
    private function lock(Delivery $delivery): array
    {
        $poId = Delivery::query()->whereKey($delivery->getKey())->value('purchase_order_id');
        $po = PurchaseOrder::query()->whereKey($poId)->lockForUpdate()->firstOrFail();
        $row = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
        $row->setRelation('purchaseOrder', $po);

        return [$po, $row];
    }

    /**
     * Cleans and checks the typed values (the Form Request checks the same shape first; this keeps the Service
     * safe if it is ever called from somewhere else). Quantities become whole thousandths.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{po_item_id: int, quantity: int, over_delivery: bool, reason: string}>
     *
     * @throws ValidationException
     */
    private function normalize(array $lines): array
    {
        $lines = array_values($lines);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Choose at least one PO line to update.']);
        }

        $errors = [];
        $clean = [];

        foreach ($lines as $index => $line) {
            $line = is_array($line) ? $line : [];
            $text = fn (string $key): string => is_scalar($line[$key] ?? null) ? trim((string) $line[$key]) : '';

            $id = $text('po_item_id');
            if (! ctype_digit($id) || (int) $id < 1) {
                $errors["lines.{$index}.po_item_id"] = 'Choose a PO line.';
            }

            $quantity = $text('quantity');
            if (! Quantity::isValidInput($quantity)) {
                $errors["lines.{$index}.quantity"] = 'Enter the quantity delivered, more than zero, with up to 3 decimals (for example 2.5).';
            }

            $clean[] = [
                'po_item_id' => (int) $id,
                'quantity' => Quantity::thousandths($quantity),
                'over_delivery' => filter_var($line['over_delivery'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'reason' => $text('over_delivery_reason'),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** @throws ValidationException */
    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of 5 to 500 characters.']);
        }

        return $reason;
    }
}
