<?php

namespace App\Support;

use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryProgress;
use App\Enums\DeliveryStatus;
use Illuminate\Support\Facades\DB;

/**
 * The running totals of step 6.1. DERIVED, NEVER STORED (decision 1.4): every number is computed from the rows
 * each time, so there is nothing to keep in step with po_items or purchase_orders.
 *
 *   delivered (per PO line)  = sum of the ACTIVE delivery_items rows of every delivery that is NOT voided
 *   undelivered              = max(0, ordered - delivered)
 *   over by                  = max(0, delivered - ordered)
 *   PO progress              = DeliveryProgress::fromTotals(sum of ordered, sum of delivered capped at ordered per line)
 *                              (capping makes "Full" mean EVERY line is complete: one over-delivered line cannot hide a missing one)
 *
 * All figures here are whole thousandths (see Quantity). A reopened delivery still counts; a voided delivery or
 * a voided row never does.
 */
final class DeliveryTotals
{
    /**
     * Delivered quantity per PO line, in thousandths. A line with nothing delivered is 0.
     *
     * @param  list<int>  $poItemIds
     * @return array<int, int> po_item_id => thousandths
     */
    public static function deliveredByLine(array $poItemIds): array
    {
        $totals = array_fill_keys($poItemIds, 0);

        if ($poItemIds === []) {
            return $totals;
        }

        $rows = DB::table('delivery_items')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_items.delivery_id')
            ->whereIn('delivery_items.po_item_id', $poItemIds)
            ->where('delivery_items.status', DeliveryItemStatus::Active->value)
            ->where('deliveries.status', '!=', DeliveryStatus::Voided->value)
            ->groupBy('delivery_items.po_item_id')
            ->selectRaw('delivery_items.po_item_id as po_item_id, SUM(delivery_items.quantity) as total')
            ->get();

        foreach ($rows as $row) {
            $totals[(int) $row->po_item_id] = Quantity::thousandths($row->total);
        }

        return $totals;
    }

    /**
     * The figures of one line.
     *
     * @param  int  $orderedPieces  po_items.quantity_ordered (a whole number)
     * @param  int  $delivered  thousandths
     * @return array{ordered: int, delivered: int, undelivered: int, over_by: int} all in thousandths
     */
    public static function figures(int $orderedPieces, int $delivered): array
    {
        $ordered = $orderedPieces * Quantity::SCALE;

        return [
            'ordered' => $ordered,
            'delivered' => $delivered,
            'undelivered' => max(0, $ordered - $delivered),
            'over_by' => max(0, $delivered - $ordered),
        ];
    }

    /**
     * The progress of several POs with two queries (the PO list page uses this, one page of POs at a time).
     * Every requested id is in the result; a PO without lines is "not delivered".
     *
     * @param  list<int>  $poIds
     * @return array<int, DeliveryProgress>
     */
    public static function progressFor(array $poIds): array
    {
        $result = array_fill_keys($poIds, DeliveryProgress::NotDelivered);

        if ($poIds === []) {
            return $result;
        }

        $lines = DB::table('po_items')
            ->whereIn('purchase_order_id', $poIds)
            ->get(['id', 'purchase_order_id', 'quantity_ordered']);

        $delivered = self::deliveredByLine($lines->pluck('id')->map(fn ($id) => (int) $id)->all());

        $ordered = array_fill_keys($poIds, 0);
        $done = array_fill_keys($poIds, 0);

        foreach ($lines as $line) {
            $poId = (int) $line->purchase_order_id;
            $lineOrdered = ((int) $line->quantity_ordered) * Quantity::SCALE;

            $ordered[$poId] += $lineOrdered;
            $done[$poId] += min($lineOrdered, $delivered[(int) $line->id] ?? 0);
        }

        foreach ($poIds as $poId) {
            // The enum takes numbers as floats; whole thousandths are exact as floats.
            $result[$poId] = DeliveryProgress::fromTotals((float) $ordered[$poId], (float) $done[$poId]);
        }

        return $result;
    }

    public static function progress(int $poId): DeliveryProgress
    {
        return self::progressFor([$poId])[$poId];
    }
}