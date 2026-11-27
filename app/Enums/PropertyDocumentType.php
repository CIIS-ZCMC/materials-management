<?php

namespace App\Enums;

/**
 * The three property documents of step 6.2: PAR, Sub-PAR and ICS. Each counts separately, per year (provisional,
 * see config/mms.php "property.numbering"). Canonical spelling: prefix + 2-digit year + hyphen + sequence padded to 4,
 * for example PAR-26-0001, SPAR-26-0001, ICS-26-0001.
 */
enum PropertyDocumentType: string
{
    case Par = 'par';
    case SubPar = 'sub_par';
    case Ics = 'ics';

    public function label(): string
    {
        return match ($this) {
            self::Par => 'PAR',
            self::SubPar => 'Sub-PAR',
            self::Ics => 'ICS',
        };
    }

    public function prefix(): string
    {
        $default = match ($this) {
            self::Par => 'PAR-',
            self::SubPar => 'SPAR-',
            self::Ics => 'ICS-',
        };

        return (string) config("mms.property.numbering.prefixes.{$this->value}", $default);
    }

    public function format(int $year, int $sequence): string
    {
        return $this->prefix()
            .str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT)
            .'-'
            .str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /** The permission needed to issue or void this document. The Sub-PAR goes with the PAR. */
    public function permission(): string
    {
        return match ($this) {
            self::Par, self::SubPar => 'par.generate',
            self::Ics => 'ics.generate',
        };
    }
}
