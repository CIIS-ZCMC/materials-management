<?php

namespace App\Models;

use App\Traits\Filterable;
use Database\Factories\ItemCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property bool $is_equipment
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'is_equipment', 'is_active'])]
class ItemCategory extends Model
{
    /** @use HasFactory<ItemCategoryFactory> */
    use Filterable, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_equipment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class, 'category_id');
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
     *
     * @param  Builder<ItemCategory>  $query
     */
    public function filterBySearch(Builder $query, mixed $value): void
    {
        $like = '%'.addcslashes((string) $value, '%_\\').'%';

        $query->where('name', 'like', $like);
    }
}
