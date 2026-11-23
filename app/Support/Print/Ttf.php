<?php
declare(strict_types=1);

namespace App\Support\Print;

/** Minimal TrueType reader (advance widths + cmap format 4). Exact text width without dompdf or GD. */
final class Ttf
{
    private int $upm = 1000;
    /** @var array<int,int> glyph id => advance */
    private array $adv = [];
    /** @var array<int,int> code point => glyph id */
    private array $cmap = [];

    public function __construct(string $path)
    {
        $d = (string) file_get_contents($path);
        $n = $this->u16($d, 4);
        $t = [];
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + $i * 16;
            $t[substr($d, $o, 4)] = $this->u32($d, $o + 8);
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $need) {
            if (!isset($t[$need])) {
                throw new \RuntimeException("{$path}: table {$need} missing");
            }
        }
        $this->upm = $this->u16($d, $t['head'] + 18);
        $nh = $this->u16($d, $t['hhea'] + 34);
        for ($g = 0; $g < $nh; $g++) {
            $this->adv[$g] = $this->u16($d, $t['hmtx'] + $g * 4);
        }
        $c = $t['cmap'];
        $nt = $this->u16($d, $c + 2);
        $sub = null;
        for ($i = 0; $i < $nt; $i++) {
            $r = $c + 4 + $i * 8;
            $pid = $this->u16($d, $r);
            $eid = $this->u16($d, $r + 2);
            if (($pid === 3 && $eid === 1) || ($pid === 0 && $sub === null)) {
                $sub = $c + $this->u32($d, $r + 4);
            }
        }
        if ($sub === null || $this->u16($d, $sub) !== 4) {
            throw new \RuntimeException("{$path}: no cmap format 4");
        }
        $segX2 = $this->u16($d, $sub + 6);
        $seg = intdiv($segX2, 2);
        $endO = $sub + 14;
        $startO = $endO + $segX2 + 2;
        $deltaO = $startO + $segX2;
        $rangeO = $deltaO + $segX2;
        for ($i = 0; $i < $seg; $i++) {
            $end = $this->u16($d, $endO + $i * 2);
            $start = $this->u16($d, $startO + $i * 2);
            $delta = $this->u16($d, $deltaO + $i * 2);
            $range = $this->u16($d, $rangeO + $i * 2);
            for ($ch = $start; $ch <= $end && $ch < 0xFFFF; $ch++) {
                if ($range === 0) {
                    $gid = ($ch + $delta) & 0xFFFF;
                } else {
                    $gid = $this->u16($d, $rangeO + $i * 2 + $range + ($ch - $start) * 2);
                    if ($gid !== 0) {
                        $gid = ($gid + $delta) & 0xFFFF;
                    }
                }
                if ($gid !== 0) {
                    $this->cmap[$ch] = $gid;
                }
            }
        }
    }

    public function width(string $text, float $size): float
    {
        $sum = 0;
        $last = count($this->adv) - 1;
        foreach (mb_str_split($text) as $ch) {
            $gid = $this->cmap[mb_ord($ch)] ?? 0;
            $sum += $this->adv[min($gid, $last)] ?? 0;
        }
        return $sum * $size / $this->upm;
    }

    private function u16(string $d, int $o): int
    {
        return (ord($d[$o]) << 8) | ord($d[$o + 1]);
    }

    private function u32(string $d, int $o): int
    {
        return ($this->u16($d, $o) << 16) | $this->u16($d, $o + 2);
    }
}
