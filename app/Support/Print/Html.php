<?php
declare(strict_types=1);

namespace App\Support\Print;

final class Html
{
    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * One line per <div>, no automatic wrapping.
     *
     * $lineHeight (pt) gives every line a FIXED height. Without it dompdf spaces the lines about 25% wider than
     * the CSS line-height says (8 pt text with line-height 10pt came out at 12.65 pt per line in step 4.6 tests),
     * the row is then taller than our own measuring and the last lines are cut off. Always pass it.
     */
    public static function lines(array $lines, string $style = '', ?float $lineHeight = null): string
    {
        $fixed = $lineHeight === null ? '' : 'height:' . $lineHeight . 'pt;line-height:' . $lineHeight . 'pt;';
        $o = '';
        foreach ($lines as $l) {
            $o .= '<div class="nw" style="' . $fixed . $style . '">' . ($l === '' ? '&nbsp;' : self::e($l)) . '</div>';
        }
        return $o;
    }

    public static function css(): string
    {
        return <<<'CSS'
@page { margin: 0; }
body { margin: 0; font-family: 'lib-sans', Helvetica, sans-serif; font-size: 8pt; color: #000; }
.page { position: relative; overflow: hidden; }
table { border-collapse: collapse; table-layout: fixed; }
td, th { padding: 0; vertical-align: top; font-weight: normal; }
.g td, .g th { border: 0.6pt solid #000; }
.serif { font-family: 'lib-serif', 'Times-Roman', serif; }
.c { text-align: center; } .r { text-align: right; } .b { font-weight: bold; }
.nw { white-space: nowrap; }
.ul { border-bottom: 0.5pt solid #000; }
CSS;
    }

    public static function wrapDoc(string $pagesHtml, string $title, string $fontCss = ''): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . self::e($title) . '</title><style>'
            . $fontCss . self::css() . '</style></head><body>' . $pagesHtml . '</body></html>';
    }
}
