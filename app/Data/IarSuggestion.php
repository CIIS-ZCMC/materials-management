<?php

namespace App\Data;

use App\Enums\IarSeriesType;

/** What the service suggests for the next IAR number. Suggesting does not use the number up. */
final readonly class IarSuggestion
{
    public function __construct(
        public IarSeriesType $type,
        public int $year,
        public int $sequence,
        public string $number,
    ) {}
}