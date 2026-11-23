<?php

namespace App\Support\Print;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * The only class that talks to dompdf (step 4.6). Takes the finished HTML of a form and returns the PDF bytes.
 *
 * Options (docs/PO-PRINT-SPIKE.md section 8): chroot = the project folder (fonts and logos are inside it),
 * fontDir/fontCache/tempDir = storage/app/dompdf (must be writable), no remote files, font subsets embedded.
 */
final class Renderer
{
    /** dompdf measured 46 MB peak for 24 PDFs in one process; 128M is the recommended php.ini value. */
    private const MEMORY_LIMIT_MB = 128;

    /**
     * @param  array{w: float, h: float}  $paper  size in pt (Paper::get)
     * @return array{pdf: string, pages: int}
     */
    public function toPdf(string $html, array $paper): array
    {
        $this->ensureMemory();

        $cache = storage_path('app/dompdf');
        File::ensureDirectoryExists($cache);

        $options = new Options;
        $options->set('chroot', (string) realpath(base_path()));
        $options->set('fontDir', $cache);
        $options->set('fontCache', $cache);
        $options->set('tempDir', $cache);
        $options->set('isRemoteEnabled', false);
        $options->set('isFontSubsettingEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->setPaper([0, 0, $paper['w'], $paper['h']]);
        $dompdf->loadHtml($html);
        $dompdf->render();

        $pdf = (string) $dompdf->output();

        return [
            'pdf' => $pdf,
            'pages' => (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf),
        ];
    }

    /** Raise memory_limit for this request when php.ini has less than the recommended value (never lowers it). */
    private function ensureMemory(): void
    {
        $current = ini_parse_quantity((string) ini_get('memory_limit'));

        if ($current !== -1 && $current > 0 && $current < self::MEMORY_LIMIT_MB * 1024 * 1024) {
            @ini_set('memory_limit', self::MEMORY_LIMIT_MB.'M');
        }
    }
}
