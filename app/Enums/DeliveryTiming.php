<?php

namespace App\Enums;

/**
 * DERIVED, never stored: the Flow C overdue indicator.
 * Computed from the due date and today (counting rule: config mms.delivery.term_counting).
 *
 * OnTime ...... not past the due date
 * Overdue ..... past the due date, still inside the grace period
 * PastGrace ... grace period used up: a Notice to Terminate may be requested, and access to the PO
 *                closes automatically unless the Head of MMS reopens it (the reopen is a stored
 *                flag/record on the PO, decided in 8.3, not a status)
 */
enum DeliveryTiming: string
{
    case OnTime = 'on_time';
    case Overdue = 'overdue';
    case PastGrace = 'past_grace';

    public function label(): string
    {
        return match ($this) {
            self::OnTime => 'On time',
            self::Overdue => 'Overdue',
            self::PastGrace => 'Past grace period',
        };
    }

    /**
     * @param  int  $daysLate  days after the due date (0 or less = not late)
     * @param  int|null  $graceDays  config mms.delivery.grace_period_days; null = not set yet,
     *                               so a PO can be Overdue but never PastGrace
     */
    public static function fromDaysLate(int $daysLate, ?int $graceDays): self
    {
        if ($daysLate <= 0) {
            return self::OnTime;
        }

        if ($graceDays === null || $daysLate <= $graceDays) {
            return self::Overdue;
        }

        return self::PastGrace;
    }
}