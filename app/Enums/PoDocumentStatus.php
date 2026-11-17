<?php

namespace App\Enums;

/**
 * Column po_documents.status. A voided document stays in the table and keeps
 * its place number; the consolidated PDF skips it (step 4.3). Voiding itself
 * (reason required, permission documents.void) is built in a later step.
 */
enum PoDocumentStatus: string
{
    case Active = 'active';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Void => 'Void',
        };
    }
}