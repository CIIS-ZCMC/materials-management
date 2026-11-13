<?php

namespace App\Models;

use Database\Factories\PoExtensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An APPROVED due-date extension. A row exists only once approved:
 * `extension.manage` records and approves in one step (no pending state), so
 * the Service (step 3.5) sets recorded_by and approved_by to the same person.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $extra_days
 * @property string $reason
 * @property int $recorded_by
 * @property int $approved_by
 * @property Carbon $approved_at
 * @property Carbon $old_due_date
 * @property Carbon $new_due_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 */
#[Fillable([
    'purchase_order_id', 'extra_days', 'reason', 'recorded_by', 'approved_by',
    'approved_at', 'old_due_date', 'new_due_date',
])]
class PoExtension extends Model
{
    /** @use HasFactory<PoExtensionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extra_days' => 'integer',
            'approved_at' => 'datetime',
            'old_due_date' => 'date:Y-m-d',
            'new_due_date' => 'date:Y-m-d',
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
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}