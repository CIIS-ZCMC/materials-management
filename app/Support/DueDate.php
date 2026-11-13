<?php

namespace App\Support;

use Carbon\CarbonInterface;
use LogicException;

/**
 * The only place that counts days for a PO due date.
 *
 *   DueDate::fromTerm($po->emailed_date, $po->delivery_term_days)
 *   DueDate::extend($dueDate, $extraDays)
 *
 * The delivery term counts from the EMAILED date (not the PO date) using
 * config('mms.delivery.term_counting'); an extension counts with
 * config('mms.delivery.extension_counting'). Both are "calendar" or
 * "working". Working days skip Saturday and Sunday; holidays come later.
 */
final class DueDate
{
    /**
     * @return CarbonInterface|null null when there is no emailed date or no term (0 or empty)
     */
    public static function fromTerm(?CarbonInterface $emailedDate, ?int $termDays): ?CarbonInterface
    {
        if ($emailedDate === null || $termDays === null || $termDays <= 0) {
            return null;
        }

        return self::add(
            $emailedDate,
            $termDays,
            (string) config('mms.delivery.term_counting'),
            'MMS_DELIVERY_TERM_COUNTING',
        );
    }

    /** The new due date after an approved extension. */
    public static function extend(CarbonInterface $dueDate, int $extraDays): CarbonInterface
    {
        return self::add(
            $dueDate,
            $extraDays,
            (string) config('mms.delivery.extension_counting'),
            'MMS_EXTENSION_COUNTING',
        );
    }

    private static function add(CarbonInterface $from, int $days, string $mode, string $setting): CarbonInterface
    {
        $start = $from->copy()->startOfDay();

        return match ($mode) {
            'calendar' => $start->addDays($days),
            'working' => $start->addWeekdays($days),
            default => throw new LogicException("Unknown value \"{$mode}\" for {$setting}; use \"calendar\" or \"working\"."),
        };
    }
}