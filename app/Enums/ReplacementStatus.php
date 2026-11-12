<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;

/**
 * STORED status of one replacement request for an item (Flow B).
 *
 * ForReplacement ... MMS selected the PO item; notice, Pull-Out Form and supplier pick-up are in progress
 * Replaced ......... replacement received (remarks and dates live on the record)
 *
 * The Pull-Out Form is a record with a date, not a status of its own.
 */
enum ReplacementStatus: string implements Transitionable
{
    use HasTransitions;

    case ForReplacement = 'for_replacement';
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::ForReplacement => 'For replacement',
            self::Replaced => 'Replacement received',
        };
    }

    public function allowedNext(): array
    {
        return match ($this) {
            self::ForReplacement => [self::Replaced],
            self::Replaced => [],
        };
    }

    public function requiresReasonFor(Transitionable $to): bool
    {
        return false;
    }
}