<?php

namespace App\Support;

/**
 * Quantity helpers (step 6.1), the same idea as Money: quantities are added up as whole THOUSANDTHS (integers),
 * so 2.5 + 0.1 never becomes 2.6000000000000001. The database column is DECIMAL(12,3).
 *
 *   thousandths("2.5")  => 2500
 *   decimal(2500)       => "2.500"   (what is saved)
 *   format(2500)        => "2.5"     (what is shown); format(500000) => "500"
 */
final class Quantity
{
    public const SCALE = 1000;

    /** DECIMAL(12,3): 999,999,999.999 */
    public const MAX_THOUSANDTHS = 999_999_999_999;

    public static function thousandths(string|int|float|null $quantity): int
    {
        return (int) round(((float) $quantity) * self::SCALE);
    }

    /** 2500 => "2.500" (plain, for the database). Never negative. */
    public static function decimal(int $thousandths): string
    {
        $thousandths = max(0, $thousandths);

        return sprintf('%d.%03d', intdiv($thousandths, self::SCALE), $thousandths % self::SCALE);
    }

    /** 2500 => "2.5", 500000 => "500" (plain, for JSON and the screen). */
    public static function format(int $thousandths): string
    {
        $text = self::decimal(abs($thousandths));
        $text = str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;

        return ($thousandths < 0 ? '-' : '').$text;
    }

    /** What a person may type: digits, at most 3 decimals, more than zero, within the column size. */
    public static function isValidInput(string $text): bool
    {
        if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', $text)) {
            return false;
        }

        $value = self::thousandths($text);

        return $value >= 1 && $value <= self::MAX_THOUSANDTHS;
    }
}