<?php

namespace App\Models;

use App\Enums\DeliveryItemStatus;
use App\Support\Quantity;
use Database\Factories\DeliveryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The quantity of one PO line delivered on one date, inside one delivery (step 6.1).
 * quantity is a decimal with 3 places (a string in PHP, so nothing drifts); use App\Support\Quantity to add it up.
 *
 * @property int $id
 * @property int $delivery_id
 * @property int $po_item_id
 * @property string $quantity
 * @property Carbon $delivered_on
 * @property DeliveryItemStatus $status
 * @property string|null $void_reason
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property bool $over_delivery
 * @property string|null $over_delivery_reason
 * @property int $recorded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Delivery $delivery
 * @property-read PoItem $poItem
 *
 * Not mass-assignable on purpose:
 * - status: changed only through App\Support\StatusTransition::apply()
 * - void_reason, voided_by, voided_at: set only when the row is voided, with a reason
 * - recorded_by: set only by DeliveryItemService::record()
 * A row is never edited after it is saved.
 */
#[Fillable([
    'delivery_id',
    'po_item_id',
    'quantity',
    'delivered_on',
    'over_delivery',
    'over_delivery_reason',
])]
class DeliveryItem extends Model
{
    /** @use HasFactory<DeliveryItemFactory> */
    use HasFactory;

    /** Defaults for a new, unsaved model (the database has the same one). */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryItemStatus::class,
            'quantity' => 'decimal:3',
            'delivered_on' => 'date:Y-m-d',
            'over_delivery' => 'boolean',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<PoItem, $this>
     */
    public function poItem(): BelongsTo
    {
        return $this->belongsTo(PoItem::class, 'po_item_id');
    }

    /**
     * The equipment decisions made for this row (step 6.2), voided ones included.
     *
     * @return HasMany<PropertyDecision, $this>
     */
    public function propertyDecisions(): HasMany
    {
        return $this->hasMany(PropertyDecision::class, 'delivery_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** Readable name for the audit log. Used by App\Support\Audit. */
    public function auditLabel(): string
    {
        $this->loadMissing(['delivery', 'poItem']);

        return "IAR {$this->delivery->iar_number}: {$this->poItem->description} x ".Quantity::format(Quantity::thousandths($this->quantity));
    }
}