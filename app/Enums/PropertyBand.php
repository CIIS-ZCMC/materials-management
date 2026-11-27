<?php

namespace App\Enums;

/**
 * The result of the property rule for one delivered line (step 6.2, PROVISIONAL, PHASE-6-PLAN Q4).
 * It is stored on the decision as a snapshot, so changing the thresholds later never rewrites an old decision.
 */
enum PropertyBand: string
{
    case NotEquipment = 'not_equipment';
    case Par = 'par';
    case Ics = 'ics';
    case Below = 'below';

    public function label(): string
    {
        return match ($this) {
            self::NotEquipment => 'Not equipment: nothing to issue',
            self::Par => 'PAR + Sub-PAR',
            self::Ics => 'ICS',
            self::Below => 'Below the ICS minimum: neither, remarks required',
        };
    }

    /** The documents this band asks for. */
    public function documents(): array
    {
        return match ($this) {
            self::Par => [PropertyDocumentType::Par, PropertyDocumentType::SubPar],
            self::Ics => [PropertyDocumentType::Ics],
            default => [],
        };
    }

    public function requiresRemarks(): bool
    {
        return $this === self::Below;
    }
}
