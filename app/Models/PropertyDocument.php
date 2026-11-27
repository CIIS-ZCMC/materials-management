<?php

namespace App\Models;

use App\Enums\PropertyDocumentStatus;
use App\Enums\PropertyDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One PAR, Sub-PAR or ICS number that was handed out (step 6.2). Never deleted; a voided document stays here so its
 * number is never reused. A Sub-PAR points to its PAR (parent_id) and carries a snapshot of the user it was issued to.
 * Written only by App\Services\PropertyDocumentService; never mass-assigned.
 *
 * @property int $id
 * @property PropertyDocumentType $type
 * @property string $number
 * @property int $property_series_id
 * @property int $sequence
 * @property int $property_decision_id
 * @property int|null $parent_id
 * @property PropertyDocumentStatus $status
 * @property string|null $user_person_id
 * @property string|null $user_employee_no
 * @property string|null $user_name
 * @property string|null $user_position
 * @property string|null $void_reason
 * @property Carbon|null $issued_at
 * @property Carbon|null $voided_at
 */
class PropertyDocument extends Model
{
    protected $attributes = [
        'status' => 'issued',
    ];

    protected function casts(): array
    {
        return [
            'type' => PropertyDocumentType::class,
            'sequence' => 'integer',
            'status' => PropertyDocumentStatus::class,
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PropertySeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(PropertySeries::class, 'property_series_id');
    }

    /** @return BelongsTo<PropertyDecision, $this> */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(PropertyDecision::class, 'property_decision_id');
    }

    /** @return BelongsTo<PropertyDocument, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<PropertyDocument, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function auditLabel(): string
    {
        return $this->type->label().' '.$this->number;
    }
}
