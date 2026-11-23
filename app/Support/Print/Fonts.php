<?php
declare(strict_types=1);

namespace App\Support\Print;

/**
 * Free look-alike fonts (Liberation Serif / Sans). Two jobs:
 *  1. fontFaceCss(): @font-face rules so dompdf loads the .ttf files from our folder (no install on the server);
 *  2. width()/wrap(): exact text widths read from the same .ttf files, so OUR code decides every line break.
 */
final class Fonts
{
    public const SERIF = 'lib-serif';
    public const SANS = 'lib-sans';

    private const FILES = [
        'lib-sans|n' => 'LiberationSans-Regular.ttf',
        'lib-sans|b' => 'LiberationSans-Bold.ttf',
        'lib-sans|i' => 'LiberationSans-Italic.ttf',
        'lib-serif|n' => 'LiberationSerif-Regular.ttf',
        'lib-serif|b' => 'LiberationSerif-Bold.ttf',
    ];

    public bool $real = true;
    /** @var array<string,Ttf> */
    private array $ttf = [];
    /** @var array<string,float> */
    private array $cache = [];

    public function __construct(private string $fontsDir)
    {
        foreach (self::FILES as $key => $file) {
            try {
                $this->ttf[$key] = new Ttf($this->fontsDir . '/' . $file);
            } catch (\Throwable) {
                $this->real = false;
            }
        }
    }

    public function fontFaceCss(): string
    {
        $dir = str_replace('\\', '/', $this->fontsDir);
        // plain path like the logos (C:/print-spike/fonts/...): dompdf strips 'file://' and then fails on '/C:/...' on Windows
        $base = $dir;
        $css = '';
        foreach (self::FILES as $key => $file) {
            [$family, $v] = explode('|', $key);
            $css .= "@font-face{font-family:'{$family}';font-weight:" . ($v === 'b' ? 'bold' : 'normal') . ';font-style:' . ($v === 'i' ? 'italic' : 'normal')
                . ";src:url('{$base}/{$file}') format('truetype');}\n";
        }
        return $css;
    }

    public function width(string $text, string $family, bool $bold, float $size): float
    {
        $key = $family . ($bold ? 'b' : 'n') . $size . '|' . $text;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $ttf = $this->ttf[$family . '|' . ($bold ? 'b' : 'n')] ?? null;
        $w = $ttf?->width($text, $size) ?? mb_strlen($text) * $size * ($family === self::SERIF ? 0.48 : 0.54) * ($bold ? 1.06 : 1.0);
        return $this->cache[$key] = $w;
    }

    /** Known value for a check: "Amoxicillin 250mg capsule (as trihydrate)" in Liberation Sans 8 pt = 145.84 pt. */
    public function selfTest(): string
    {
        $w = $this->width('Amoxicillin 250mg capsule (as trihydrate)', self::SANS, false, 8.0);
        return sprintf('%.2f pt (expected 145.84)%s', $w, abs($w - 145.84) < 0.05 ? ' OK' : ' MISMATCH');
    }

    /** @return list<string> lines that each fit in $maxWidth (pt) */
    public function wrap(string $text, float $maxWidth, string $family, bool $bold, float $size): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [''] as $para) {
            $words = preg_split('/\s+/', trim($para), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                $out[] = '';
                continue;
            }
            $line = '';
            foreach ($words as $word) {
                $try = $line === '' ? $word : $line . ' ' . $word;
                if ($this->width($try, $family, $bold, $size) <= $maxWidth) {
                    $line = $try;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                    $line = '';
                }
                while ($this->width($word, $family, $bold, $size) > $maxWidth && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word) - 1;
                    while ($cut > 1 && $this->width(mb_substr($word, 0, $cut), $family, $bold, $size) > $maxWidth) {
                        $cut--;
                    }
                    $out[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $line = $word;
            }
            $out[] = $line;
        }
        return $out;
    }
}
