<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\IarSeriesType;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One delivery batch of a PO = one IAR (step 5.2, CONTEXT.md decision 47). Header only:
 * per-item delivered quantities belong to step 6.1.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property DeliveryStatus $status
 * @property string $invoice_no
 * @property IarSeriesType $iar_series
 * @property string $iar_number
 * @property string $fund_cluster
 * @property int $amount_cents
 * @property Carbon $date_received
 * @property string|null $void_reason
 * @property int|null $voided_by
 * @property Carbon|null $voided_at
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 *
 * Not mass-assignable on purpose:
 * - status: changed only through App\Support\StatusTransition::apply()
 * - void_reason, voided_by, voided_at: set only by DeliveryService::void(), with a reason
 * - created_by: set only by DeliveryService::create()
 * The IAR number and series are typed once, when the delivery is created, and never edited
 * (DeliveryService::update() does not touch them).
 */
#[Fillable([
    'purchase_order_id',
    'invoice_no',
    'iar_series',
    'iar_number',
    'fund_cluster',
    'amount_cents',
    'date_received',
])]
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory;

    /** Defaults for a new, unsaved model (the database has the same one). */
    protected $attributes = [
        'status' => 'for_uploading',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'iar_series' => IarSeriesType::class,
            'amount_cents' => 'integer',
            'date_received' => 'date:Y-m-d',
            'voided_at' => 'datetime',
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
     * Inspectors first, then the end-user, in the order they were chosen.
     *
     * @return HasMany<DeliverySignatory, $this>
     */
    public function signatories(): HasMany
    {
        return $this->hasMany(DeliverySignatory::class)->orderBy('role')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasOne<IarNumber, $this>
     */
    public function iarNumber(): HasOne
    {
        return $this->hasOne(IarNumber::class, 'delivery_id');
    }

    /**
     * The DR, OR, IAR and supporting files of this delivery (step 5.3).
     *
     * @return HasMany<PoDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PoDocument::class, 'delivery_id')->orderBy('sort_order');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** The two-digit year inside the IAR number (26-1398 gives 26), whatever the series prefix. */
    public function iarYearShort(): int
    {
        return (int) substr((string) $this->iar_number, strlen($this->iar_series->prefix()), 2);
    }

    /** Readable name for the audit log. Used by App\Support\Audit. */
    public function auditLabel(): string
    {
        return (string) $this->iar_number;
    }
}
