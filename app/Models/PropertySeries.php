<?php

namespace App\Models;

use App\Enums\PropertyDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One counter: a property document type (PAR, Sub-PAR, ICS) in a year (step 6.2). next_number is the number the next
 * suggestion starts from. Written only by App\Services\PropertyNumberService, never mass-assigned.
 */
class PropertySeries extends Model
{
    protected $table = 'property_series';

    protected function casts(): array
    {
        return [
            'type' => PropertyDocumentType::class,
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PropertyDocument::class, 'property_series_id');
    }

    public function auditLabel(): string
    {
        return $this->type->label().' '.$this->year;
    }
}
