<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The one place that starts the qpdf program (docs/PDF-MERGE.md).
 * The program path is an absolute path in .env (MMS_QPDF_PATH), so the web
 * server's PATH does not matter.
 *
 * Exit codes of qpdf: 0 = fine, 3 = fine with warnings (seen on real office PO
 * scans; the output is still written), 2 = damaged, unreadable or password-protected.
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

    /**
     * Number of pages of a PDF ("qpdf --show-npages"). Returns 0 when qpdf cannot read the file
     * (exit codes 0 and 3 both give a usable number).
     *
     * @throws RuntimeException when the path is not set or the program cannot be started
     */
    public static function pageCount(string $file): int
    {
        $result = Process::timeout(60)->run([self::path(), '--show-npages', $file]);

        if (! in_array((int) $result->exitCode(), [0, 3], true)) {
            return 0;
        }

        return max(0, (int) trim($result->output()));
    }

    /**
     * Combines PDFs into one: "qpdf --empty --pages a.pdf b.pdf -- out.pdf" (docs/PDF-MERGE.md
     * section 6). The ORDER of $files is the page order. qpdf copies the pages as they are:
     * nothing is resized, rotated or re-compressed, so mixed page sizes stay as scanned.
     *
     * Returns the exit code (0 and 3 mean the output was written) and qpdf's error text,
     * which is for the log only.
     *
     * @param  list<string>  $files  absolute paths
     * @return array{exit: int, error: string}
     *
     * @throws RuntimeException when the path is not set or the program cannot be started
     */
    public static function merge(array $files, string $output): array
    {
        $result = Process::timeout(180)->run([self::path(), '--empty', '--pages', ...$files, '--', $output]);

        return [
            'exit' => (int) $result->exitCode(),
            'error' => trim(mb_substr($result->errorOutput(), 0, 2000)),
        ];
    }
}