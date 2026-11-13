<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown by the BizBox provider while BizBox is not set up (before Phase 13). */
class BizBoxNotConfigured extends RuntimeException
{
    public function __construct(string $message = 'BizBox is not configured')
    {
        parent::__construct($message);
    }
}