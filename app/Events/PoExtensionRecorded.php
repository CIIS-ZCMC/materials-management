<?php

namespace App\Events;

use App\Models\PoExtension;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after an extension is saved. No email is sent in Phases 0-10; in Phase 11
 * a listener will notify the supplier.
 */
class PoExtensionRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly PoExtension $extension) {}
}