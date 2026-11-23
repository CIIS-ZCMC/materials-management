<?php

namespace App\Enums;

/** Used = the number belongs to a delivery (or is reserved for one). Void = cancelled, never reused. */
enum IarNumberStatus: string
{
    case Used = 'used';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Used => 'Used',
            self::Void => 'Void',
        };
    }
}