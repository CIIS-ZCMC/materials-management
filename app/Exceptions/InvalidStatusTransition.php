<?php

namespace App\Exceptions;

use App\Enums\Transitionable;
use Illuminate\Validation\ValidationException;

/**
 * Thrown by App\Support\StatusTransition when a status change is refused.
 * It is a ValidationException, so the message reaches the form as a normal
 * validation error (key "status") instead of a server error page.
 */
class InvalidStatusTransition extends ValidationException
{
    /** The enum does not allow this move (for example Draft to Verified). */
    public static function notAllowed(Transitionable $from, Transitionable $to): self
    {
        return self::withMessages([
            'status' => "A record that is \"{$from->label()}\" cannot be changed to \"{$to->label()}\".",
        ]);
    }

    /** The move needs a written reason and none was given. */
    public static function reasonRequired(Transitionable $from, Transitionable $to): self
    {
        return self::withMessages([
            'reason' => "A reason is required to change \"{$from->label()}\" to \"{$to->label()}\".",
        ]);
    }

    /** The record was changed by someone else after it was loaded. */
    public static function changedMeanwhile(Transitionable $current): self
    {
        return self::withMessages([
            'status' => "This record was changed by someone else and is now \"{$current->label()}\". Reload the page and try again.",
        ]);
    }
}