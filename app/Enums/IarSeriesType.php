<?php

namespace App\Enums;

/**
 * The three IAR number series (CONTEXT.md decision 6). Each counts separately, per year:
 * regular 26-1398, contract C26-0015, donation D26-0001.
 */
enum IarSeriesType: string
{
    case Regular = 'regular';
    case Contract = 'contract';
    case Donation = 'donation';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Contract => 'Contract',
            self::Donation => 'Donation',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Regular => '',
            self::Contract => 'C',
            self::Donation => 'D',
        };
    }

    /** The one canonical spelling of a number: prefix, 2-digit year, hyphen, sequence padded to 4 digits. */
    public function format(int $year, int $sequence): string
    {
        return $this->prefix()
            .str_pad((string) ($year % 100), 2, '0', STR_PAD_LEFT)
            .'-'
            .str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}