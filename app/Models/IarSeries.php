<?php

namespace App\Models;

use App\Enums\IarSeriesType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One counter: a series type in a year. next_number is the number the next suggestion starts from.
 * Written only by App\Services\IarNumberService (and the dev seeder), never mass-assigned.
 */
class IarSeries extends Model
{
    protected $table = 'iar_series';

    protected function casts(): array
    {
        return [
            'type' => IarSeriesType::class,
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }

    public function numbers(): HasMany
    {
        return $this->hasMany(IarNumber::class, 'iar_series_id');
    }

    public function auditLabel(): string
    {
        return $this->type->label().' '.$this->year;
    }
}