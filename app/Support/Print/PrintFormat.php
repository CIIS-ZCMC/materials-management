<?php

namespace App\Support\Print;

/**
 * Number formats of the printed forms. Money is whole centavos (integers), so a total never drifts.
 * Kept inside the print code so App\Support\Money (used by the pages) is not touched.
 */
final class PrintFormat
{
    /** 5400000 centavos -> "54,000.00" */
    public static function money(int $centavos): string
    {
        return number_format(intdiv($centavos, 100)).'.'.str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 15000 -> "15,000" */
    public static function qty(int $quantity): string
    {
        return number_format($quantity);
    }
}
