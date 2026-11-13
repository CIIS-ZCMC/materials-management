<?php

namespace App\Support;

/**
 * Money helpers. Amounts are summed as whole centavos (integers) so totals never drift.
 */
final class Money
{
    public static function cents(string|int|float|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** 125050 => "1250.50" (plain, for JSON). */
    public static function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    /** 125050 => "PHP 1,250.50" (for audit text). */
    public static function php(int $cents): string
    {
        return 'PHP '.number_format($cents / 100, 2);
    }
}