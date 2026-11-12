<?php

namespace App\Models;

use App\Traits\Filterable;
use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $category_id
 * @property int $unit_id
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ItemCategory $category
 * @property-read Unit $unit
 */
#[Fillable(['name', 'category_id', 'unit_id', 'is_active'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use Filterable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
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
     * Readable name for the audit log. Used by App\Support\Audit.
     */
    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Filter hook used by Filterable::scopeFilter (key: "search").
     * Matches the item name, its category name, and its unit name.
     *
     * @param  Builder<Item>  $query
     */
    public function filterBySearch(Builder $query, mixed $value): void
    {
        $like = '%'.addcslashes((string) $value, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', $like))
                ->orWhereHas('unit', fn (Builder $u) => $u->where('name', 'like', $like));
        });
    }
}
