<?php

namespace App\Models;

use App\Enums\PropertyBand;
use App\Enums\PropertyDecisionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The equipment decision for ONE delivered line (step 6.2): is it equipment, and what does the rule say about it.
 * Everything the rule used is a snapshot (unit price, thresholds, whether the rule was provisional), so the record
 * stays true after the thresholds change. Never edited: void it (reason) and decide again.
 * Written only by App\Services\PropertyDecisionService; never mass-assigned.
 *
 * @property int $id
 * @property int $delivery_item_id
 * @property bool $is_equipment
 * @property bool $category_default
 * @property string|null $override_reason
 * @property int $unit_price_cents
 * @property PropertyBand $band
 * @property int $par_threshold_cents
 * @property int $ics_min_cents
 * @property bool $rule_provisional
 * @property string|null $remarks
 * @property PropertyDecisionStatus $status
 * @property string|null $void_reason
 * @property int|null $decided_by
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property Carbon|null $created_at
 */
class PropertyDecision extends Model
{
    /** Defaults for a new, unsaved model (the database has the same one). */
    protected $attributes = [
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'is_equipment' => 'boolean',
            'category_default' => 'boolean',
            'unit_price_cents' => 'integer',
            'band' => PropertyBand::class,
            'par_threshold_cents' => 'integer',
            'ics_min_cents' => 'integer',
            'rule_provisional' => 'boolean',
            'status' => PropertyDecisionStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DeliveryItem, $this> */
    public function deliveryItem(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class, 'delivery_item_id');
    }

    /** @return HasMany<PropertyDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(PropertyDocument::class, 'property_decision_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function auditLabel(): string
    {
        $this->loadMissing('deliveryItem');

        return 'Equipment decision, '.($this->deliveryItem?->auditLabel() ?? 'line #'.$this->delivery_item_id);
    }
}
