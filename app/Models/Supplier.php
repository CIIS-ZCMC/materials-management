<?php

namespace App\Models;

use App\Traits\Filterable;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $contact_person
 * @property string|null $address
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'contact_person', 'address', 'is_active'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
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
     * Readable name for the audit log. Used by App\Support\Audit.
     */
    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Filter hook used by Filterable::scopeFilter (key: "search").
     * Matches name, contact person, and email.
     *
     * @param  Builder<Supplier>  $query
     */
    public function filterBySearch(Builder $query, mixed $value): void
    {
        $like = '%'.addcslashes((string) $value, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('contact_person', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }
}