<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;

/**
 * STORED status of ONE delivered-item row (step 6.1).
 *
 * Active ..... the row counts in the totals (as long as its delivery is not voided)
 * Voided ..... entered by mistake, or its delivery was voided; kept on record with a reason, never counted
 *
 * A row is never edited: a correction is a void (reason required) plus a new row.
 */
enum DeliveryItemStatus: string implements Transitionable
{
    use HasTransitions;

    case Active = 'active';
    case Voided = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Voided => 'Voided',
        };
    }

    public function allowedNext(): array
    {
        return match ($this) {
            self::Active => [self::Voided],
            self::Voided => [],
        };
    }

    public function requiresReasonFor(Transitionable $to): bool
    {
        return $to === self::Voided;
    }
}