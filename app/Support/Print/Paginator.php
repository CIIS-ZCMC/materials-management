<?php
declare(strict_types=1);

namespace App\Support\Print;

/**
 * Our own pagination: rows are placed by estimated/measured height, never by the PDF engine.
 *
 * @param list<float> $heights  height of every line (pt)
 * @param float $capFirst       free height for lines on the first page (pt)
 * @param float $capMiddle      free height for lines on every other page (pt)
 * @param float $lastExtra      extra height the LAST page needs for the total rows (pt)
 * @param int|null $maxRows     maximum lines per page (04A: 10)
 * @return list<array{items: list<int>, used: float, overflow: bool}>
 */
final class Paginator
{
    public static function paginate(array $heights, float $capFirst, float $capMiddle, float $lastExtra, ?int $maxRows = null): array
    {
        if ($heights === []) {
            return [['items' => [], 'used' => 0.0, 'overflow' => false]];
        }

        $pages = [];
        $cur = ['items' => [], 'used' => 0.0, 'overflow' => false];
        $capFor = static fn (int $pageIndex): float => $pageIndex === 0 ? $capFirst : $capMiddle;

        foreach ($heights as $i => $h) {
            $cap = $capFor(count($pages));
            $full = $cur['items'] !== [] && ($cur['used'] + $h > $cap || ($maxRows !== null && count($cur['items']) >= $maxRows));
            if ($full) {
                $pages[] = $cur;
                $cur = ['items' => [], 'used' => 0.0, 'overflow' => false];
                $cap = $capFor(count($pages));
            }
            if ($h > $cap) {
                $cur['overflow'] = true; // taller than a whole page: placed alone, will be clipped
            }
            $cur['items'][] = $i;
            $cur['used'] += $h;
        }
        $pages[] = $cur;

        // The last page must also hold the total rows. Move lines to a new page until it fits.
        while (true) {
            $li = count($pages) - 1;
            $cap = $capFor($li);
            $last = $pages[$li];
            if ($last['used'] + $lastExtra <= $cap || $last['overflow']) {
                break;
            }
            if (count($last['items']) <= 1) {
                // a single line and still no room for the totals: totals-only page
                $pages[] = ['items' => [], 'used' => 0.0, 'overflow' => false];
                break;
            }
            $moved = array_pop($pages[$li]['items']);
            $pages[$li]['used'] -= $heights[$moved];
            $pages[] = ['items' => [$moved], 'used' => $heights[$moved], 'overflow' => $heights[$moved] > $capMiddle];
        }

        return $pages;
    }
}
