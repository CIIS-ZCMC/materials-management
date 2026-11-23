<?php
declare(strict_types=1);

namespace App\Support\Print;

/**
 * Absolute-position helpers. dompdf ignores column widths of HTML tables (the first spike run showed equal
 * columns), so every box, cell and label is placed by us with explicit left/top/width/height in pt.
 */
final class Ab
{
    public static function d(float $x, float $y, float $w, ?float $h, string $html, string $style = ''): string
    {
        return '<div style="position:absolute;left:' . round($x, 2) . 'pt;top:' . round($y, 2) . 'pt;width:' . round($w, 2) . 'pt;'
            . ($h !== null ? 'height:' . round($h, 2) . 'pt;' : '') . $style . '">' . $html . '</div>';
    }

    /** a thin horizontal line */
    public static function line(float $x, float $y, float $w, string $style = ''): string
    {
        return self::d($x, $y, $w, 0.0, '', 'border-top:0.5pt solid #000;' . $style);
    }

    /**
     * One grid row. $w = column widths (pt). $cells = list of ['h' => html, 'span' => n, 'st' => css, 'pl','pr','pt' => padding].
     * Outer size of the row is array_sum($w) x $h (borders included).
     */
    public static function row(array $w, array $cells, float $h, bool $top = false): string
    {
        $W = array_sum($w);
        $x = 0.0;
        $ci = 0;
        $o = '';
        foreach ($cells as $c) {
            $span = $c['span'] ?? 1;
            $cw = 0.0;
            for ($k = 0; $k < $span; $k++) {
                $cw += $w[$ci + $k];
            }
            $ci += $span;
            $pl = $c['pl'] ?? 0.0;
            $pr = $c['pr'] ?? 0.0;
            $pt = $c['pt'] ?? 0.0;
            $o .= '<div style="position:absolute;left:' . round($x, 2) . 'pt;top:0pt;width:' . round($cw - 0.6 - $pl - $pr, 2) . 'pt;height:' . round($h - 0.6 - $pt - ($top ? 0.6 : 0), 2)
                . 'pt;padding:' . $pt . 'pt ' . $pr . 'pt 0pt ' . $pl . 'pt;border-right:0.6pt solid #000;overflow:hidden;' . ($c['st'] ?? '') . '">' . ($c['h'] ?? '') . '</div>';
            $x += $cw;
        }
        return '<div style="position:relative;width:' . round($W - 0.6, 2) . 'pt;height:' . round($h - 0.6 - ($top ? 0.6 : 0), 2)
            . 'pt;border-left:0.6pt solid #000;border-bottom:0.6pt solid #000;' . ($top ? 'border-top:0.6pt solid #000;' : '') . '">' . $o . '</div>';
    }

    /**
     * Supplier box content (labels + values in two halves). $rows = list of [label1, valueHtml1, label2, valueHtml2, rowHeight].
     * $underlineBox true: full-width line under each value (form 13); false: value is underlined text (form 04A).
     */
    public static function supplier(float $cw, array $rows, float $lblL, float $lblR, float $fs, bool $underlineBox): string
    {
        $half = $cw / 2;
        $y = 0.0;
        $o = '';
        $valStyle = $underlineBox ? '' : 'font-weight:bold;text-decoration:underline;';
        foreach ($rows as [$l1, $v1, $l2, $v2, $h]) {
            $o .= self::d(6, $y + 2, $lblL - 6, null, Html::e($l1), 'white-space:nowrap');
            $o .= self::d($lblL, $y + 2, $half - $lblL - 8, null, $v1, $valStyle);
            $o .= self::d($half + 6, $y + 2, $lblR - 6, null, Html::e($l2), 'white-space:nowrap');
            $o .= self::d($half + $lblR, $y + 2, $half - $lblR - 8, null, $v2, $valStyle);
            if ($underlineBox) {
                $o .= self::line($lblL, $y + $h - 1, $half - $lblL - 8) . self::line($half + $lblR, $y + $h - 1, $half - $lblR - 8);
            }
            $y += $h;
        }
        return '<div style="position:relative;height:' . ($y + 2) . 'pt;font-size:' . $fs . 'pt;">' . $o . '</div>';
    }
}
