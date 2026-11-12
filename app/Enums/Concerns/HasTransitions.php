<?php

namespace App\Enums\Concerns;

use App\Enums\Transitionable;
use App\Support\StatusTransition;

/**
 * Shared helpers for the stored-status enums. The enum itself only has to
 * define label(), allowedNext() and requiresReasonFor().
 */
trait HasTransitions
{
    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    public function canMoveTo(Transitionable $to): bool
    {
        return StatusTransition::can($this, $to);
    }

    /**
     * value => label, for filter drop-downs and badges.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}