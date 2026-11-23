<?php
declare(strict_types=1);

namespace App\Support\Print;

/**
 * Pure PHP, no intl. Wording as printed on the forms:
 *   "Five Million Four Hundred Thousand and 0/100 Cents Only"   (form 13)
 *   "... Fifty-One and 80/100 cents only"                        (form 04A)
 */
final class AmountInWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    private const SCALES = ['', 'Thousand', 'Million', 'Billion', 'Trillion'];

    public static function words(int $n): string
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Negative amount');
        }
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        $scale = 0;
        while ($n > 0) {
            $group = $n % 1000;
            if ($group > 0) {
                $text = self::below1000($group);
                $parts[] = $text . (self::SCALES[$scale] !== '' ? ' ' . self::SCALES[$scale] : '');
            }
            $n = intdiv($n, 1000);
            $scale++;
        }
        return implode(' ', array_reverse($parts));
    }

    /** @param string $suffix 'Cents Only' (form 13) or 'cents only' (form 04A) */
    public static function amount(int $centavos, string $suffix = 'Cents Only'): string
    {
        $pesos = intdiv($centavos, 100);
        $c = $centavos % 100;
        // 0 centavos prints "0/100" (as on the scans). Other values: two digits. To confirm with the office.
        $cents = $c === 0 ? '0' : str_pad((string) $c, 2, '0', STR_PAD_LEFT);
        return self::words($pesos) . ' and ' . $cents . '/100 ' . $suffix;
    }

    private static function below1000(int $n): string
    {
        $out = [];
        if ($n >= 100) {
            $out[] = self::ONES[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $t = self::TENS[intdiv($n, 10)];
            $o = $n % 10;
            $out[] = $o > 0 ? $t . '-' . self::ONES[$o] : $t;
        } elseif ($n > 0) {
            $out[] = self::ONES[$n];
        }
        return implode(' ', $out);
    }
}
