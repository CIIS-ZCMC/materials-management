<?php

namespace App\Exceptions;

use App\Enums\Transitionable;
use Illuminate\Validation\ValidationException;

/**
 * Thrown by StatusTransition::apply() when a change is not allowed.
 *
 * It is a ValidationException on purpose: Inertia sends the message back to
 * the form (shown as a toast or under the field), like the guards in
 * UserService.
 */
final class InvalidStatusTransition extends ValidationException
{
    public static function notAllowed(Transitionable $from, Transitionable $to): self
    {
        $next = array_map(fn (Transitionable $status) => $status->label(), $from->allowedNext());

        $hint = $next === []
            ? "\"{$from->label()}\" is a final status."
            : 'It can only go to: '.implode(', ', $next).'.';

        return self::withMessages([
            'status' => "The status cannot change from \"{$from->label()}\" to \"{$to->label()}\". {$hint}",
        ]);
    }

    public static function reasonRequired(Transitionable $from, Transitionable $to): self
    {
        return self::withMessages([
            'reason' => "Please write a reason for changing from \"{$from->label()}\" to \"{$to->label()}\".",
        ]);
    }

    public static function changedMeanwhile(Transitionable $current): self
    {
        return self::withMessages([
            'status' => "Someone else already changed this record (it is now \"{$current->label()}\"). Reload the page and try again.",
        ]);
    }
}