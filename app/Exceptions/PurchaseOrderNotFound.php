<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown by a provider when no PO matches the reference. */
class PurchaseOrderNotFound extends RuntimeException
{
    public static function forReference(string $reference): self
    {
        return new self("No purchase order found for reference '{$reference}'.");
    }
}