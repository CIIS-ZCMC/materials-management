<?php

namespace App\Models;

use Database\Factories\PoItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a PO. The description is a snapshot, so the PO stays readable
 * if the catalog changes. item_id is null for a BizBox line that is not in
 * the catalog.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int|null $item_id
 * @property string $description
 * @property int $category_id
 * @property int $unit_id
 * @property string $unit_price
 * @property int $quantity_ordered
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PurchaseOrder $purchaseOrder
 * @property-read Item|null $item
 * @property-read ItemCategory $category
 * @property-read Unit $unit
 */
#[Fillable(['purchase_order_id', 'item_id', 'description', 'category_id', 'unit_id', 'unit_price', 'quantity_ordered'])]
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
}