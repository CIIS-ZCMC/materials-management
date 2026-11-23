<?php
declare(strict_types=1);

namespace App\Support\Print;

final class Paper
{
    public const MARGIN_X = 22.7; // 8 mm
    public const MARGIN_TOP = 22.7;

    /** @return array{key:string,label:string,w:float,h:float} */
    public static function get(string $key): array
    {
        return match ($key) {
            'a4' => ['key' => 'a4', 'label' => 'A4 (210 x 297 mm)', 'w' => 595.28, 'h' => 841.89],
            'bond' => ['key' => 'bond', 'label' => 'Long bond (8.5 x 13 in)', 'w' => 612.0, 'h' => 936.0],
            default => throw new \InvalidArgumentException("Unknown paper {$key}"),
        };
    }

    /** content width, content height of one page (pt) */
    public static function inner(array $paper): array
    {
        return [$paper['w'] - 2 * self::MARGIN_X, $paper['h'] - self::MARGIN_TOP - 4.0];
    }
}
