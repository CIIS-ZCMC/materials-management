<?php

namespace App\Enums;

/**
 * DERIVED, never stored: how much of a PO has arrived.
 * Computed from ordered quantity versus the sum of all delivery records.
 */
enum DeliveryProgress: string
{
    case NotDelivered = 'not_delivered';
    case Partial = 'partial';
    case Full = 'full';

    public function label(): string
    {
        return match ($this) {
            self::NotDelivered => 'Not yet delivered',
            self::Partial => 'Partially delivered',
            self::Full => 'Fully delivered',
        };
    }

    /** @param  float  $ordered  total ordered; $delivered total received so far */
    public static function fromTotals(float $ordered, float $delivered): self
    {
        if ($ordered <= 0 || $delivered <= 0) {
            return self::NotDelivered;
        }

        return $delivered >= $ordered ? self::Full : self::Partial;
    }
}