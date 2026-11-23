<?php

namespace App\Models;

use App\Enums\IarNumberStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One IAR number that was handed out. Never deleted; a voided number stays here so it is never reused.
 * Written only by App\Services\IarNumberService.
 */
class IarNumber extends Model
{
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'status' => IarNumberStatus::class,
            'reserved_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(IarSeries::class, 'iar_series_id');
    }

    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function auditLabel(): string
    {
        return (string) $this->number;
    }
}