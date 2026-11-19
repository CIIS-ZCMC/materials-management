<?php

namespace App\Models;

use Database\Factories\PoScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One scheduled delivery date of a PO (Schedule Term). Since step 3.3c a PO may also state, per line,
 * how many units arrive on this date (quantities(), table po_schedule_items); a PO without a split has none.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property Carbon $scheduled_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PoScheduleItem> $quantities
 */
#[Fillable(['purchase_order_id', 'scheduled_date'])]
class PoSchedule extends Model
{
    /** @use HasFactory<PoScheduleFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * The quantity of each line on this date (step 3.3c). Empty for a PO without a split.
     *
     * @return HasMany<PoScheduleItem, $this>
     */
    public function quantities(): HasMany
    {
        return $this->hasMany(PoScheduleItem::class, 'po_schedule_id');
    }
}
