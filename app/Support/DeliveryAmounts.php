<?php

namespace App\Support;

use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryStatus;
use App\Models\Delivery;
use Illuminate\Support\Facades\DB;

/**
 * Step 6.1d: the money checks around a delivery. A CHECK, NEVER A BLOCK: nothing here stops a save, and the typed
 * Amount of a delivery (deliveries.amount_cents) is never changed. Like DeliveryTotals, everything is DERIVED from the
 * rows each time and NEVER STORED.
 *
 *   value of a delivery   = sum over its ACTIVE delivery_items of  quantity x the PO line's unit price
 *                           (whole centavos; each row rounded half up, so 2.5 x 10.50 = 26.25 and nothing drifts)
 *   a delivery "matches"  when its typed Amount and that value differ by no more than the tolerance
 *   deliveries total      = the typed Amounts of every delivery of the PO that is NOT voided
 *   PO total              = sum of unit price x quantity ordered over the PO lines (the same maths as the Items tab)
 *
 * The tolerance is config('mms.delivery.amount_tolerance_cents') (env MMS_AMOUNT_TOLERANCE_CENTS), default 0 = exact to
 * the centavo. It is provisional: MMS has not said what the Amount is (the supplier's invoice total?) or whether it must
 * equal quantity x price. A voided row and a voided delivery never count.
 */
final class DeliveryAmounts
{
    public const NONE = 'none';

    public const MATCHES = 'matches';

    public const DIFFERS = 'differs';

    /** The allowed difference in centavos (never negative). */
    public static function toleranceCents(): int
    {
        return max(0, (int) config('mms.delivery.amount_tolerance_cents', 0));
    }

    /**
     * quantity x unit price for ONE row, in whole centavos, rounded half up.
     * Quantity is thousandths and price is centavos, so the product is thousandths of a centavo: whole-number maths only.
     */
    public static function valueCents(string|int|float|null $quantity, string|int|float|null $unitPrice): int
    {
        $product = Quantity::thousandths($quantity) * Money::cents($unitPrice);

        return intdiv($product + intdiv(Quantity::SCALE, 2), Quantity::SCALE);
    }

    /**
     * The value of the ACTIVE rows of one delivery in centavos, or null when it has none (nothing entered yet).
     */
    public static function deliveryValueCents(int $deliveryId): ?int
    {
        $rows = DB::table('delivery_items')
            ->join('po_items', 'po_items.id', '=', 'delivery_items.po_item_id')
            ->where('delivery_items.delivery_id', $deliveryId)
            ->where('delivery_items.status', DeliveryItemStatus::Active->value)
            ->get(['delivery_items.quantity as quantity', 'po_items.unit_price as unit_price']);

        if ($rows->isEmpty()) {
            return null;
        }

        $total = 0;

        foreach ($rows as $row) {
            $total += self::valueCents($row->quantity, $row->unit_price);
        }

        return $total;
    }

    /**
     * Typed Amount against the value of the quantities.
     *
     * @param  int|null  $tolerance  centavos; null reads the config
     * @return array{state: string, difference_cents: int|null} difference = typed Amount minus value (null when nothing entered)
     */
    public static function compare(int $amountCents, ?int $valueCents, ?int $tolerance = null): array
    {
        if ($valueCents === null) {
            return ['state' => self::NONE, 'difference_cents' => null];
        }

        $difference = $amountCents - $valueCents;
        $tolerance ??= self::toleranceCents();

        return [
            'state' => abs($difference) <= $tolerance ? self::MATCHES : self::DIFFERS,
            'difference_cents' => $difference,
        ];
    }

    /**
     * What the delivery page shows next to the typed Amount.
     *
     * @return array{amount: string, value: string|null, difference: string|null, direction: string|null, state: string}
     *         difference is always positive text; direction says whether the TYPED Amount is "higher" or "lower" than the value.
     *         Both are null unless the state is "differs".
     */
    public static function checkFor(Delivery $delivery): array
    {
        $amount = (int) $delivery->amount_cents;
        $value = self::deliveryValueCents($delivery->id);
        $result = self::compare($amount, $value);
        $differs = $result['state'] === self::DIFFERS;

        return [
            'amount' => Money::format($amount),
            'value' => $value === null ? null : Money::format($value),
            'difference' => $differs ? Money::format(abs((int) $result['difference_cents'])) : null,
            'direction' => $differs ? ((int) $result['difference_cents'] > 0 ? 'higher' : 'lower') : null,
            'state' => $result['state'],
        ];
    }

    /** The PO total in centavos: unit price x quantity ordered over every line (as the Items tab). */
    public static function poTotalCents(int $poId): int
    {
        $total = 0;

        foreach (DB::table('po_items')->where('purchase_order_id', $poId)->get(['unit_price', 'quantity_ordered']) as $line) {
            $total += Money::cents($line->unit_price) * (int) $line->quantity_ordered;
        }

        return $total;
    }

    /**
     * For the Deliveries tab of the PO page: the typed Amounts of every delivery that is not voided, next to the PO total.
     * over_by is set only when the deliveries add up to MORE than the PO total (beyond the tolerance); a smaller total is
     * normal while a PO is partly delivered.
     *
     * @return array{deliveries_total: string, po_total: string, over_by: string|null, counted: int}
     */
    public static function forPo(int $poId): array
    {
        $query = DB::table('deliveries')
            ->where('purchase_order_id', $poId)
            ->where('status', '!=', DeliveryStatus::Voided->value);

        $counted = (int) (clone $query)->count();
        $sum = (int) (clone $query)->sum('amount_cents');
        $po = self::poTotalCents($poId);
        $over = $sum - $po;

        return [
            'deliveries_total' => Money::format($sum),
            'po_total' => Money::format($po),
            'over_by' => $over > self::toleranceCents() ? Money::format($over) : null,
            'counted' => $counted,
        ];
    }
}