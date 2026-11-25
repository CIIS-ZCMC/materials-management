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
 *                        row, re-check DeliveryItemRule, check every line (same PO, date, over-delivery), insert, audit.
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
     * @return array{today: string, min_date: string|null, progress: array{value: string, label: string}, lines: list<array<string, mixed>>}
     */
    public function sheet(Delivery $delivery): array
    {
        $delivery->loadMissing('purchaseOrder');
        $po = $delivery->purchaseOrder;

        return [
            'today' => $this->today(),
            'min_date' => $po->po_date?->toDateString(),
            'progress' => $this->progress($po->id),
            'lines' => $this->lines($po, withRows: false),
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

        return [
            'can_update' => $update->allowed(),
            // Only people who could act are told why they cannot.
            'locked_reason' => $handles && ! $update->allowed() ? $update->message() : null,
            'progress' => $this->progress($delivery->purchase_order_id),
            'lines' => $this->lines($delivery->purchaseOrder, only: $delivery),
        ];
    }

    /**
     * For the PO page (Deliveries tab): the derived progress and every line with its history across all deliveries.
     *
     * @return array{progress: array{value: string, label: string}, lines: list<array<string, mixed>>}
     */
    public function poSummary(PurchaseOrder $po): array
    {
        return [
            'progress' => $this->progress($po->id),
            'lines' => $this->lines($po),
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
     *
     * @param  Delivery|null  $only  show only the rows of this delivery (the totals still count every delivery)
     * @return list<array<string, mixed>>
     */
    private function lines(PurchaseOrder $po, ?Delivery $only = null, bool $withRows = true): array
    {
        $poItems = PoItem::query()->with('unit:id,name')->where('purchase_order_id', $po->id)->orderBy('id')->get();
        $delivered = DeliveryTotals::deliveredByLine($poItems->pluck('id')->map(fn ($id) => (int) $id)->all());

        $rows = collect();

        if ($withRows) {
            $rows = ($only !== null ? $this->items->forDelivery($only->id) : $this->items->forPurchaseOrder($po->id))
                ->groupBy('po_item_id');
        }

        /** @var array<int, bool> $voidable delivery id => whether this person may void rows of it */
        $voidable = [];

        return $poItems->map(function (PoItem $line) use ($delivered, $rows, &$voidable): array {
            $figures = DeliveryTotals::figures($line->quantity_ordered, $delivered[$line->id] ?? 0);

            return [
                'po_item_id' => $line->id,
                'description' => $line->description,
                'unit' => $line->unit?->name,
                'ordered' => Quantity::format($figures['ordered']),
                'delivered' => Quantity::format($figures['delivered']),
                'undelivered' => Quantity::format($figures['undelivered']),
                'over_by' => $figures['over_by'] > 0 ? Quantity::format($figures['over_by']) : null,
                'rows' => collect($rows->get($line->id, []))
                    ->map(function (DeliveryItem $row) use (&$voidable): array {
                        $voidable[$row->delivery_id] ??= Gate::allows('voidItem', $row->delivery);

                        return $this->rowData($row, $voidable[$row->delivery_id]);
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
     * Records several PO lines in one save: each with its own date and the quantity delivered. All or nothing.
     *
     * @param  list<array<string, mixed>>  $lines  po_item_id, quantity, delivered_on, over_delivery, over_delivery_reason
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

                if (isset($seen[$id])) {
                    $errors["lines.{$index}.po_item_id"] = 'This line is entered twice. Enter it once per save.';

                    continue;
                }

                $seen[$id] = true;

                if ($message = $this->dateProblem($po, $line['delivered_on'])) {
                    $errors["lines.{$index}.delivered_on"] = $message;
                }

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
                    'delivered_on' => $line['delivered_on'],
                    'over_delivery' => $plan['over'],
                    // An over-delivery tick on a line that does not go over is ignored: no flag, no reason.
                    'over_delivery_reason' => $plan['over'] ? $line['reason'] : null,
                ]);
                $item->forceFill(['recorded_by' => $actor->id])->save();

                $saved[] = $item;

                $text = $poItem->description.': '.Quantity::format($line['quantity']).($plan['unit'] ? " {$plan['unit']}" : '')
                    .' on '.$line['delivered_on'].' (total '.Quantity::format($plan['after']).' of '.Quantity::format($plan['ordered']).')';

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
     * @return list<array{po_item_id: int, quantity: int, delivered_on: string, over_delivery: bool, reason: string}>
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

            $date = $text('delivered_on');
            if (! $this->isDate($date)) {
                $errors["lines.{$index}.delivered_on"] = 'Enter the delivery date as YYYY-MM-DD.';
            }

            $clean[] = [
                'po_item_id' => (int) $id,
                'quantity' => Quantity::thousandths($quantity),
                'delivered_on' => $date,
                'over_delivery' => filter_var($line['over_delivery'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'reason' => $text('over_delivery_reason'),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /** The delivery date may not be in the future (Manila date) or before the PO date. */
    private function dateProblem(PurchaseOrder $po, string $date): ?string
    {
        if ($date > $this->today()) {
            return 'The delivery date cannot be in the future.';
        }

        $poDate = $po->po_date?->toDateString();

        if ($poDate !== null && $date < $poDate) {
            return "The delivery date cannot be before the PO date ({$poDate}).";
        }

        return null;
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

    private function isDate(string $value): bool
    {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function today(): string
    {
        return now()->setTimezone((string) config('mms.display_timezone', 'Asia/Manila'))->toDateString();
    }
}