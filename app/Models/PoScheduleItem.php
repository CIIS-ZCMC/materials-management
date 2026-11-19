<?php

namespace App\Models;

use Database\Factories\PoScheduleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The quantity of one PO line on one scheduled delivery date (step 3.3c). Zero is stored on
 * purpose: form 04A prints "0" in a column where nothing arrives. Written only by
 * App\Support\ScheduleSplit, which keeps every line's quantities adding up to its quantity ordered.
 *
 * @property int $id
 * @property int $po_schedule_id
 * @property int $po_item_id
 * @property int $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PoSchedule $schedule
 * @property-read PoItem $item
 */
#[Fillable(['po_schedule_id', 'po_item_id', 'quantity'])]
class PoScheduleItem extends Model
{
    /** @use HasFactory<PoScheduleItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PoSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PoSchedule::class, 'po_schedule_id');
    }

    /**
     * @return BelongsTo<PoItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PoItem::class, 'po_item_id');
    }
}
