<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Times are stored in UTC and shown in config('mms.display_timezone')
 * (Asia/Manila). Use this for any date shown to users (audit log,
 * last_login_at, PO dates later) so every page agrees.
 */
final class DisplayTime
{
    public static function timezone(): string
    {
        return (string) config('mms.display_timezone', 'Asia/Manila');
    }

    public static function format(?CarbonInterface $at, string $format = 'M j, Y g:i:s A'): ?string
    {
        return $at?->copy()->setTimezone(self::timezone())->format($format);
    }

    /**
     * Turn two "YYYY-MM-DD" dates typed in the display timezone into UTC
     * bounds (start of the first day, end of the last day). Bad input is
     * ignored. If from is after to, they are swapped.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function dayBoundsUtc(?string $from, ?string $to): array
    {
        $start = self::parseDay($from)?->startOfDay();
        $end = self::parseDay($to)?->endOfDay();

        if ($start && $end && $start->greaterThan($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return [$start?->utc(), $end?->utc()];
    }

    private static function parseDay(?string $date): ?CarbonImmutable
    {
        if ($date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone()) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
