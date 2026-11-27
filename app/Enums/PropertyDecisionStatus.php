<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;

/**
 * STORED status of ONE equipment decision (step 6.2).
 *
 * Active ..... the decision stands
 * Voided ..... recorded wrongly; kept on record with a reason. A decision is never edited: void it and decide again.
 */
enum PropertyDecisionStatus: string implements Transitionable
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
