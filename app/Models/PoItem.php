<?php

namespace App\Models;

use Database\Factories\PoItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One line of a PO. The description is a snapshot, so the PO stays readable
 * if the catalog changes. item_id is null for a BizBox line that is not in
 * the catalog. stock_no is a snapshot of the catalog stock number; brand,
 * manufacturer, country_of_origin and specifications are typed per line
 * (they are printed on the PO forms). term_quantity (step 3.3c) is the part of
 * quantity_ordered due within the PO's delivery term from receipt (first column of form 04A);
 * null = the PO has no split. The rest is split per date in po_schedule_items.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $item_id
 * @property string $description
 * @property int $category_id
 * @property int $unit_id
 * @property string $unit_price
 * @property int $quantity_ordered
 * @property int|null $term_quantity
 * @property string|null $stock_no
 * @property string|null $brand
 * @property string|null $manufacturer
 * @property string|null $country_of_origin
 * @property string|null $specifications
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Item|null $item
 * @property-read ItemCategory $category
 * @property-read Unit $unit
 */
#[Fillable([
    'purchase_order_id', 'item_id', 'description', 'category_id', 'unit_id', 'unit_price', 'quantity_ordered',
    'stock_no', 'brand', 'manufacturer', 'country_of_origin', 'specifications', 'term_quantity',
])]
class PoItem extends Model
{
    /** @use HasFactory<PoItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity_ordered' => 'integer',
            'term_quantity' => 'integer',
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
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The quantities delivered for this line (step 6.1), voided rows included. Totals are computed by
     * App\Support\DeliveryTotals, never stored.
     *
     * @return HasMany<DeliveryItem, $this>
     */
    public function deliveryItems(): HasMany
    {
        return $this->hasMany(DeliveryItem::class, 'po_item_id');
    }

    /**
     * This line's quantity on each scheduled date (step 3.3c). Empty for a PO without a split.
     *
     * @return HasMany<PoScheduleItem, $this>
     */
    public function scheduleQuantities(): HasMany
    {
        return $this->hasMany(PoScheduleItem::class, 'po_item_id');
    }
}