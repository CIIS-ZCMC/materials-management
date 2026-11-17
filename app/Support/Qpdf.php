<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The one place that starts the qpdf program (docs/PDF-MERGE.md).
 * The program path is an absolute path in .env (MMS_QPDF_PATH), so the web
 * server's PATH does not matter. Step 4.3 adds the merge call here.
 *
 * Exit codes of "qpdf --check": 0 = fine, 3 = fine with warnings (seen on real
 * office PO scans), 2 = damaged, unreadable or password-protected.
 */
final class Qpdf
{
    /** @throws RuntimeException when MMS_QPDF_PATH is not set */
    public static function path(): string
    {
        $path = config('mms.pdf.qpdf_path');

        if (! is_string($path) || trim($path) === '') {
            throw new RuntimeException('MMS_QPDF_PATH is not set in .env.');
        }

        return trim($path);
    }

    /**
     * Runs "qpdf --check" and returns the exit code.
     *
     * @throws RuntimeException when the path is not set or the program cannot be started
     */
    public static function check(string $file): int
    {
        $result = Process::timeout(60)->run([self::path(), '--check', $file]);

        return (int) $result->exitCode();
    }
}