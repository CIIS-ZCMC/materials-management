<?php

namespace App\Models;

use Database\Factories\PoScheduleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scheduled delivery date of a PO (Schedule Term). Dates only for now.
 * A quantity per date and item may come later as its own table.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property Carbon $scheduled_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
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
}