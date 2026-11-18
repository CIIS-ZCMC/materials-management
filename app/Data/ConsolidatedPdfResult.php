<?php

namespace App\Data;

/**
 * What PoConsolidatedPdfService::rebuild() tells the caller. A failed rebuild is a normal
 * outcome (the previous consolidated PDF stays), so it is a value, not an exception.
 */
final readonly class ConsolidatedPdfResult
{
    private function __construct(
        public bool $ok,
        public int $pages,
        public ?string $message,
        public bool $warnings,
    ) {}

    public static function built(int $pages, bool $warnings = false): self
    {
        return new self(true, $pages, null, $warnings);
    }

    /** The PO has no active document, so there is nothing to combine (any old consolidated file is removed). */
    public static function empty(): self
    {
        return new self(true, 0, null, false);
    }

    public static function failed(string $message): self
    {
        return new self(false, 0, $message, false);
    }
}