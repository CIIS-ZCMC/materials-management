<?php

namespace App\Models;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Support\DueDate;
use App\Traits\Filterable;
use Carbon\CarbonInterface;
use Database\Factories\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $po_number
 * @property int $supplier_id
 * @property Carbon|null $po_date
 * @property Carbon|null $emailed_date
 * @property int|null $delivery_term_days
 * @property Carbon|null $due_date
 * @property PurchaseOrderSource $source
 * @property string|null $external_id
 * @property PurchaseOrderStatus $status
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Supplier $supplier
 *
 * Not mass-assignable on purpose:
 * - status: changed only through App\Support\StatusTransition::apply()
 * - due_date: set by recalculateDueDate()
 * - closed_at: set only by the future PurchaseOrderService::syncClosed()
 *   (steps 7.3 and 8.1). It is stamped the first time the PO is closed
 *   (fully delivered or cancelled/terminated, AND paid) and cleared if
 *   that stops being true. Nobody types it.
 */
#[Fillable(['po_number', 'supplier_id', 'po_date', 'emailed_date', 'delivery_term_days', 'source', 'external_id'])]
class PurchaseOrder extends Model
{
    /** @use HasFactory<PurchaseOrderFactory> */
    use Filterable, HasFactory;

    /** Defaults for a new, unsaved model (the database has the same ones). */
    protected $attributes = [
        'source' => 'manual',
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'po_date' => 'date:Y-m-d',
            'emailed_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'delivery_term_days' => 'integer',
            'source' => PurchaseOrderSource::class,
            'status' => PurchaseOrderStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<PoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PoItem::class);
    }

    /**
     * @return HasMany<PoSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(PoSchedule::class)->orderBy('scheduled_date');
    }

    /**
     * Oldest first, so extensions are applied in the order they were approved.
     *
     * @return HasMany<PoExtension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(PoExtension::class)->orderBy('id');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * POs that are not closed (the default list; closed ones stay searchable).
     *
     * @param  Builder<PurchaseOrder>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * Due date from the emailed date and delivery term, plus every approved
     * extension. Null when there is no emailed date or no term.
     */
    public function calculateDueDate(): ?CarbonInterface
    {
        $due = DueDate::fromTerm($this->emailed_date, $this->delivery_term_days);

        if ($due === null || ! $this->exists) {
            return $due;
        }

        foreach ($this->extensions()->pluck('extra_days') as $days) {
            $due = DueDate::extend($due, (int) $days);
        }

        return $due;
    }

    /**
     * Sets due_date WITHOUT saving. Call it in the same save whenever
     * emailed_date or delivery_term_days changes, or an extension is approved.
     */
    public function recalculateDueDate(): void
    {
        $this->due_date = $this->calculateDueDate();
    }

    /**
     * Readable name for the audit log. Used by App\Support\Audit.
     */
    public function auditLabel(): string
    {
        return $this->po_number;
    }

    /**
     * Filter hook used by Filterable::scopeFilter (key: "search").
     * Matches the PO number and the supplier name.
     *
     * @param  Builder<PurchaseOrder>  $query
     */
    public function filterBySearch(Builder $query, mixed $value): void
    {
        $like = '%'.addcslashes((string) $value, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('po_number', 'like', $like)
                ->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', $like));
        });
    }
}