<?php

namespace App\Support;

use Exception;
use FPDF;
use InvalidArgumentException;

/**
 * Turns one JPG or PNG into a one-page PDF (docs/PDF-MERGE.md section 3.2).
 *
 * - A JPEG is embedded as is (FPDF copies the bytes; no decoding, no quality loss).
 * - A phone photo's EXIF orientation becomes the page's /Rotate (90, 180, 270).
 * - The page is A4 portrait with a 5 mm margin. Whether to use long bond is
 *   part of the open paper-size question (docs/PO-TEMPLATE.md 2.1); change the
 *   three constants below if MMS decides.
 *
 * FPDF throws a plain Exception for images it cannot use (for example a
 * 16-bit PNG); the caller turns that into a plain message.
 */
final class ImagePdf
{
    private const PAGE_WIDTH_MM = 210;

    private const PAGE_HEIGHT_MM = 297;

    private const MARGIN_MM = 5;

    /** EXIF orientation => PDF /Rotate. Mirrored orientations (2, 4, 5, 7) are rare and ignored. */
    private const ROTATION = [3 => 180, 6 => 90, 8 => 270];

    /**
     * @param  'jpg'|'png'  $kind
     * @return string the PDF file content
     *
     * @throws Exception
     */
    public static function fromFile(string $path, string $kind, int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Image has no size.');
        }

        $rotation = $kind === 'png' ? 0 : (self::ROTATION[self::exifOrientation($path)] ?? 0);
        $sideways = in_array($rotation, [90, 270], true);

        // Size as the person will see it (after the page rotation).
        $shownWidth = $sideways ? $height : $width;
        $shownHeight = $sideways ? $width : $height;

        $scale = min(
            (self::PAGE_WIDTH_MM - 2 * self::MARGIN_MM) / $shownWidth,
            (self::PAGE_HEIGHT_MM - 2 * self::MARGIN_MM) / $shownHeight,
        );

        $imageWidth = $width * $scale;
        $imageHeight = $height * $scale;
        $type = $kind === 'png' ? 'PNG' : 'JPG';

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);

        if ($rotation !== 0) {
            // FPDF keeps a custom page size as portrait and swaps it for 'L',
            // so the orientation letter must match the raw image shape.
            $pdf->AddPage($imageWidth > $imageHeight ? 'L' : 'P', [$imageWidth, $imageHeight], $rotation);
            $pdf->Image($path, 0, 0, $imageWidth, $imageHeight, $type);
        } else {
            $pdf->AddPage('P', [self::PAGE_WIDTH_MM, self::PAGE_HEIGHT_MM]);
            $pdf->Image(
                $path,
                (self::PAGE_WIDTH_MM - $imageWidth) / 2,
                (self::PAGE_HEIGHT_MM - $imageHeight) / 2,
                $imageWidth,
                $imageHeight,
                $type,
            );
        }

        return $pdf->Output('S');
    }

    /** True when the PNG uses interlacing (FPDF cannot read it). Byte 28 of the file is the IHDR interlace flag. */
    public static function isInterlacedPng(string $path): bool
    {
        $head = @file_get_contents($path, false, null, 0, 29);

        return is_string($head) && strlen($head) >= 29 && ord($head[28]) === 1;
    }

    /**
     * EXIF orientation (1 to 8) of a JPEG, 1 when there is none. A small reader
     * of the first 64 KB, so the "exif" PHP extension is not needed.
     */
    public static function exifOrientation(string $path): int
    {
        $data = @file_get_contents($path, false, null, 0, 65536);

        if (! is_string($data) || strlen($data) < 12 || substr($data, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $length = strlen($data);
        $pos = 2;

        while ($pos + 4 <= $length) {
            if ($data[$pos] !== "\xFF") {
                return 1;
            }

            $marker = ord($data[$pos + 1]);

            if ($marker === 0xDA || $marker === 0xD9) { // image data or end: no EXIF before it
                return 1;
            }

            $size = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);

            if ($size < 2) {
                return 1;
            }

            if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
                return self::readOrientation($data, $pos + 10);
            }

            $pos += 2 + $size;
        }

        return 1;
    }

    /** Reads the orientation tag (0x0112) from the TIFF block that starts at $tiff. */
    private static function readOrientation(string $data, int $tiff): int
    {
        $order = substr($data, $tiff, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }

        $little = $order === 'II';

        $u16 = static function (int $offset) use ($data, $little): ?int {
            $bytes = substr($data, $offset, 2);

            return strlen($bytes) === 2 ? (int) unpack($little ? 'v' : 'n', $bytes)[1] : null;
        };

        $u32 = static function (int $offset) use ($data, $little): ?int {
            $bytes = substr($data, $offset, 4);

            return strlen($bytes) === 4 ? (int) unpack($little ? 'V' : 'N', $bytes)[1] : null;
        };

        $first = $u32($tiff + 4);

        if ($first === null) {
            return 1;
        }

        $ifd = $tiff + $first;
        $count = $u16($ifd);

        if ($count === null) {
            return 1;
        }

        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + 12 * $i;

            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}