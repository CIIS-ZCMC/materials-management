<?php
declare(strict_types=1);

namespace App\Support\Print;

/**
 * ZCMC-F-PROC-13 Rev. 12: the standard PO (step 4.6). One fixed-size block per printed page, everything placed
 * by us (see Ab). It only lays out the view model made by App\Services\PoPrintService; it reads no database and
 * no config. The height constants below are the only numbers to tune (docs/PO-PRINT-SPIKE.md).
 *
 * View model keys used: po_no, po_date, pr_no, caf_no, mode, delivery_term_days, free_text, supplier, lines,
 * header (4 lines), place (name, address), payment_term, certification, signatory, accountant, form.
 */
final class Form13
{
    // fixed heights (pt): the only numbers to tune
    private const H_HEADER = 62.0;     // 50 of header + 12 of space below it
    private const H_TITLE = 32.0;
    private const H_GENT = 14.0;
    private const H_PLACE = 38.0;
    private const H_TABLE_HEAD = 28.0;
    private const H_SIGNATURE = 180.0;
    private const H_FUND = 84.0;
    private const H_FOOTER = 26.0;
    private const LEAD = 10.0;
    private const FS = 8.0;
    private const ROW_PAD = 4.0;
    private const ROW_MIN = 16.0;
    private const H_BOTTOM = 13.0 + 13.0 + 14.0 + 22.0;

    public function __construct(private Fonts $fonts, private string $assets)
    {
    }

    /** @return array{html:string,pages:int,warnings:list<string>,plan:list<array<string,mixed>>} */
    public function build(array $po, array $paper): array
    {
        [$cw, $innerH] = Paper::inner($paper);
        $warnings = [];
        $half = $cw / 2;
        $lblL = 82.0;
        $lblR = 90.0;
        $s = $po['supplier'];

        // supplier box rows (the name and the address wrap by our own measuring)
        $valW = $half - $lblL - 10;
        $nameLines = $this->fonts->wrap($s['name'], $valW, Fonts::SANS, true, 8.0);
        $addrLines = $this->fonts->wrap($s['address'], $valW, Fonts::SANS, false, 8.0);
        $hName = max(14.0, count($nameLines) * 10.0 + 6.0);
        $hAddr = max(14.0, count($addrLines) * 10.0 + 6.0);
        $taxes = Ab::d($valW * 0.6, 0, 60, null, 'Taxes :');
        $supplierRows = [
            ['Supplier :', Html::lines($nameLines, 'font-weight:bold', 10.0), 'P.O. No. :', '<b>' . Html::e($po['po_no']) . '</b>', $hName],
            ['Address:', Html::lines($addrLines, '', 10.0), 'P.O. Date :', Html::e($po['po_date']), $hAddr],
            ['Supply Chain Role:', Html::e($s['role']), 'P.R. No. :', Html::e($po['pr_no']), 14.0],
            ['Contact No:', Html::e($s['contact']), 'CAF No. :', Html::e($po['caf_no']), 14.0],
            ['TIN:', Html::e($s['tin']) . $taxes, 'Mode of Procurement:', Html::e($po['mode']), 14.0],
        ];
        $supplierInner = array_sum(array_column($supplierRows, 4)) + 2.0;
        $supplierH = $supplierInner + 1.2;
        $supplierHtml = '<div style="position:relative;height:' . $supplierInner . 'pt;border:0.6pt solid #000;">'
            . Ab::supplier($cw - 1.2, $supplierRows, $lblL, $lblR, 8.0, true) . '</div>';

        // columns of the item table
        $shares = [7.0, 6.0, 48.0, 12.5, 12.5, 14.0];
        $gw = $cw - 1.4; // grid width: a little narrower than the page, so the right border is never clipped
        $colW = array_map(static fn (float $x): float => round($gw * $x / 100, 2), $shares);
        $colW[5] = round($gw - array_sum(array_slice($colW, 0, 5)), 2);
        $descW = $colW[2] - 4 - 4;

        $rows = [];
        $heights = [];
        foreach ($po['lines'] as $i => $ln) {
            $lines = $this->fonts->wrap($ln['name'], $descW, Fonts::SANS, false, self::FS);
            if ($ln['spec'] !== []) {
                $lines[] = '';
                foreach ($ln['spec'] as $sp) {
                    foreach ($this->fonts->wrap($sp, $descW, Fonts::SANS, false, self::FS) as $w) {
                        $lines[] = $w;
                    }
                }
            }
            $rows[$i] = $lines;
            $heights[$i] = max(self::ROW_MIN, count($lines) * self::LEAD + self::ROW_PAD);
        }

        $fixed = self::H_HEADER + self::H_TITLE + $supplierH + self::H_GENT + self::H_PLACE + self::H_SIGNATURE + self::H_FUND + self::H_FOOTER;
        $areaH = $innerH - $fixed;
        $cap = $areaH - self::H_TABLE_HEAD - 0.6;
        $pages = Paginator::paginate($heights, $cap, $cap, self::H_BOTTOM);

        $total = 0;
        foreach ($po['lines'] as $ln) {
            $total += $ln['qty'] * $ln['price'];
        }
        $words = AmountInWords::amount($total, 'Cents Only');

        $html = '';
        $plan = [];
        foreach ($pages as $p => $pg) {
            $isLast = $p === count($pages) - 1;
            if ($pg['overflow']) {
                $warnings[] = 'page ' . ($p + 1) . ': a line is taller than the page and is clipped';
            }
            $rowsHtml = '';
            foreach ($pg['items'] as $idx) {
                $rowsHtml .= $this->itemRow($colW, $po['lines'][$idx], $rows[$idx], $heights[$idx]);
            }
            if ($isLast) {
                $rowsHtml .= $this->bottomRows($colW, $total, $po['free_text'], $words);
            }
            $html .= $this->page($p, $po, $colW, $cw, $innerH, $areaH, $supplierHtml, $supplierH, $rowsHtml);
            $plan[] = ['page' => $p + 1, 'rows' => count($pg['items']), 'used_pt' => round($pg['used'], 1), 'cap_pt' => round($cap - ($isLast ? self::H_BOTTOM : 0), 1)];
        }

        return ['html' => Html::wrapDoc($html, 'Form 13 ' . $po['po_no'], $this->fonts->fontFaceCss()), 'pages' => count($pages), 'warnings' => $warnings, 'plan' => $plan];
    }

    private function itemRow(array $w, array $ln, array $desc, float $h): string
    {
        $amount = $ln['qty'] * $ln['price'];
        return Ab::row($w, [
            ['h' => Html::e($ln['stock']), 'st' => 'text-align:center;font-size:7pt', 'pt' => 2],
            ['h' => Html::e($ln['unit']), 'st' => 'text-align:center;font-size:7pt', 'pt' => 2],
            ['h' => Html::lines($desc, '', self::LEAD), 'st' => 'font-size:' . self::FS . 'pt;line-height:' . self::LEAD . 'pt', 'pl' => 4, 'pr' => 4, 'pt' => 2],
            ['h' => PrintFormat::qty($ln['qty']), 'st' => 'text-align:right', 'pr' => 4, 'pt' => 2],
            ['h' => PrintFormat::money($ln['price']), 'st' => 'text-align:right;font-weight:bold', 'pr' => 4, 'pt' => 2],
            ['h' => PrintFormat::money($amount), 'st' => 'text-align:right', 'pr' => 4, 'pt' => 2],
        ], $h);
    }

    private function bottomRows(array $w, int $total, string $free, string $words): string
    {
        return Ab::row($w, [
            ['span' => 2], ['h' => str_repeat('x-', 22) . 'x', 'st' => 'text-align:center;white-space:nowrap;font-size:7pt', 'pt' => 1], ['span' => 2],
            ['h' => PrintFormat::money($total), 'st' => 'text-align:right;font-weight:bold', 'pr' => 4, 'pt' => 1],
        ], 13.0)
            . Ab::row($w, [['span' => 6]], 13.0)
            . Ab::row($w, [['h' => Html::e($free), 'span' => 6, 'st' => 'text-align:center', 'pt' => 2]], 14.0)
            . Ab::row($w, [
                ['h' => Html::lines(['(Total Amount', 'in Words)'], '', 8.0), 'span' => 2, 'st' => 'text-align:center;font-size:6.5pt', 'pt' => 3],
                ['h' => Html::e($words), 'span' => 4, 'st' => 'text-align:center', 'pt' => 6],
            ], 22.0);
    }

    private function page(int $n, array $po, array $colW, float $cw, float $innerH, float $areaH, string $supplierHtml, float $supplierH, string $rowsHtml): string
    {
        $out = '<div class="page" style="width:' . $cw . 'pt;height:' . $innerH . 'pt;padding:' . Paper::MARGIN_TOP . 'pt ' . Paper::MARGIN_X . 'pt 0 ' . Paper::MARGIN_X . 'pt;'
            . ($n > 0 ? 'page-break-before:always;' : '') . '">';

        // header: two seals beside the centred text, then 8 pt of space before the title
        $x0 = ($cw - 390) / 2;
        $out .= '<div style="position:relative;height:' . self::H_HEADER . 'pt">'
            . '<img src="' . $this->assets . '/zcmc.png" style="position:absolute;left:' . ($x0 + 6) . 'pt;top:3pt;height:44pt">'
            . Ab::d($x0 + 50, 4, 290, 46, Html::lines($po['header'], '', 11.0), 'text-align:center;font-family:\'lib-serif\',serif;font-size:9.5pt;line-height:11pt')
            . '<img src="' . $this->assets . '/DOH-small.png" style="position:absolute;left:' . ($x0 + 344) . 'pt;top:3pt;height:44pt">'
            . '</div>';
        $out .= '<div class="serif c b" style="font-size:14pt;height:' . self::H_TITLE . 'pt;line-height:22pt">PURCHASE ORDER</div>';
        $out .= $supplierHtml;

        $out .= '<div style="height:' . (self::H_GENT - 1.2) . 'pt;border:0.6pt solid #000;border-top:0;overflow:hidden;font-size:7pt;padding:2pt 6pt 0 6pt;white-space:nowrap">'
            . 'Gentlemen:&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;Please furnish this Office the following articles subject to the terms and conditions contained herein:</div>';

        $days = (int) ($po['delivery_term_days'] ?? 0);
        $term = $days > 0 ? $days . ($days === 1 ? ' Day Term' : ' Days Term') : '';
        $place = Ab::d(4, 2, $cw * 0.6, null, 'Place of Delivery: ' . Html::e($po['place']['name']), 'white-space:nowrap')
            . Ab::d(66, 12, $cw * 0.6, null, Html::e($po['place']['address']), 'white-space:nowrap;font-size:6.5pt')
            . Ab::d(4, 26, 70, null, 'Date of Delivery:', 'white-space:nowrap')
            . Ab::d(110, 26, 160, null, '(To be filled by the MMS)', 'white-space:nowrap')
            . Ab::d($cw * 0.62, 2, $cw * 0.36, null, 'Delivery Term: ' . Html::e($term), 'white-space:nowrap')
            . Ab::d($cw * 0.62, 20, $cw * 0.36, null, 'Payment Term: ' . Html::e($po['payment_term']), 'white-space:nowrap');
        $out .= '<div style="position:relative;height:' . (self::H_PLACE - 1.2) . 'pt;border:0.6pt solid #000;border-top:0;overflow:hidden;font-size:7pt">' . $place . '</div>';

        $head = Ab::row($colW, [
            ['h' => Html::lines(['Stock/', 'Property', 'No.'], '', 8.0), 'st' => 'text-align:center', 'pt' => 2],
            ['h' => 'Unit', 'st' => 'text-align:center', 'pt' => 10],
            ['h' => 'Description', 'st' => 'text-align:center', 'pt' => 10],
            ['h' => 'Quantity', 'st' => 'text-align:center', 'pt' => 10],
            ['h' => 'Unit Cost', 'st' => 'text-align:center', 'pt' => 10],
            ['h' => 'Amount', 'st' => 'text-align:center', 'pt' => 10],
        ], self::H_TABLE_HEAD, true);
        $out .= '<div style="height:' . $areaH . 'pt;overflow:hidden;font-size:7pt;font-weight:bold"><div style="margin-left:0.7pt">' . $head
            . '<div style="font-weight:normal;font-size:8pt">' . $rowsHtml . '</div></div></div>';

        $out .= $this->signature($cw, $po);
        $out .= $this->fund($cw, $po);
        $out .= $this->footer($cw, $n + 1, $po);
        return $out . '</div>';
    }

    private function box(float $h, string $inner, string $style = ''): string
    {
        return '<div style="height:' . ($h - 1.2) . 'pt;border:0.6pt solid #000;overflow:hidden;' . $style . '">' . $inner . '</div>';
    }

    private function signature(float $cw, array $po): string
    {
        $cert = $this->fonts->wrap($po['certification'], $cw - 20, Fonts::SANS, true, 7.0);
        $in = '<div class="c" style="height:56pt;font-size:8pt;line-height:11pt"><div style="padding-top:3pt">Very Truly yours,</div><div style="height:22pt"></div>'
            . '<div class="b">' . Html::e($po['signatory']['name']) . '</div><div>' . Html::e($po['signatory']['title']) . '</div></div>'
            . '<div style="height:14pt;padding-left:6pt;font-size:8pt">Conforme:</div>'
            . '<div class="b c" style="height:24pt;font-size:7pt;line-height:9pt;padding:0 6pt">' . Html::lines($cert, '', 9.0) . '</div>'
            . '<div class="c" style="height:72pt;font-size:8pt;line-height:11pt"><div style="height:26pt"></div>'
            . '<div style="font-size:10pt;height:14pt">' . Html::e('') . '</div>'
            . '<div style="font-size:7pt;height:10pt">Signature over Printed Name of Supplier</div>'
            . '<div style="height:12pt">' . Html::e('') . '</div><div style="font-size:7pt">Date Received</div></div>';
        return $this->box(self::H_SIGNATURE, $in);
    }

    private function fund(float $cw, array $po): string
    {
        $half = ($cw - 1.2) / 2;
        $o = '';
        foreach ([['Fund Cluster:', 6.0], ['Funds Available:', 22.0]] as [$l, $y]) {
            $o .= Ab::d(8, $y, 92, null, $l, 'white-space:nowrap') . Ab::line(100, $y + 11, $half - 110);
        }
        $o .= Ab::d(8, 46, $half - 16, null, '<div class="b" style="font-size:7.5pt">' . Html::e($po['accountant']['name']) . '</div>' . Html::e($po['accountant']['title']), 'text-align:center;font-size:7pt;line-height:9pt');
        foreach ([['ORS/BURS No.:', 6.0], ['Date of the ORS/BURS:', 22.0], ['Amount:', 38.0]] as [$l, $y]) {
            $o .= Ab::d($half + 8, $y, 100, null, $l, 'white-space:nowrap') . Ab::line($half + 110, $y + 11, $half - 120);
        }
        return '<div style="position:relative;height:' . (self::H_FUND - 2.2) . 'pt;border:0.6pt solid #000;border-top:1.4pt solid #000;overflow:hidden;font-size:8pt">' . $o . '</div>';
    }

    private function footer(float $cw, int $page, array $po): string
    {
        return '<div style="position:relative;height:' . self::H_FOOTER . 'pt;font-size:6.5pt">'
            . Ab::d(0, 3, $cw * 0.42, null, '<i>(Adopted from Government Accounting Manual: Appendix 61)</i><br>' . Html::e($po['form']['number']), 'white-space:nowrap')
            . Ab::d($cw * 0.46, 9, 50, null, 'Rev. ' . Html::e($po['form']['revision']), 'white-space:nowrap')
            . Ab::d($cw * 0.58, 9, 160, null, 'Effectivity date: ' . Html::e($po['form']['effectivity']), 'white-space:nowrap')
            . Ab::d($cw - 80, 9, 80, null, 'Page:' . $page, 'text-align:right')
            . '</div>';
    }
}
