<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;

/**
 * STORED status of ONE property document (step 6.2).
 *
 * Issued ..... the number is in use
 * Voided ..... cancelled with a reason; the number stays on record and is never reused. A document is never edited:
 *              void it and issue a new one (a new number).
 */
enum PropertyDocumentStatus: string implements Transitionable
{
    use HasTransitions;

    case Issued = 'issued';
    case Voided = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Voided => 'Voided',
        };
    }

    public function allowedNext(): array
    {
        return match ($this) {
            self::Issued => [self::Voided],
            self::Voided => [],
        };
    }

    public function requiresReasonFor(Transitionable $to): bool
    {
        return $to === self::Voided;
    }
}
