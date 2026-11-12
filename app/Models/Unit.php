<?php

namespace App\Models;

use App\Traits\Filterable;
use Database\Factories\UnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'code', 'is_active'])]
class Unit extends Model
{
    /** @use HasFactory<UnitFactory> */
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
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
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
     * Matches name and short code.
     *
     * @param  Builder<Unit>  $query
     */
    public function filterBySearch(Builder $query, mixed $value): void
    {
        $like = '%'.addcslashes((string) $value, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('code', 'like', $like);
        });
    }
}
