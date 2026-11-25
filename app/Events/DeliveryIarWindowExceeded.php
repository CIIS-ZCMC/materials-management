<?php

namespace App\Events;

use App\Models\Delivery;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired ONCE per delivery (step 5.3) when the IAR processing window has passed and the delivery still
 * waits for its documents. Nothing listens yet on purpose: the consequence is not defined by MMS, and no
 * email is sent (decision: Phase 11). A listener can be added later without touching the delivery code.
 */
final class DeliveryIarWindowExceeded
{
    use Dispatchable;

    public function __construct(
        public readonly Delivery $delivery,
        public readonly string $deadline,
    ) {}
}
