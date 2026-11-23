<?php

namespace App\Models;

use App\Contracts\PersonnelDirectory;
use App\Traits\Filterable;
use Database\Factories\PersonnelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $employee_no
 * @property string $name
 * @property string|null $position
 * @property string|null $office
 * @property bool $is_inspector
 * @property bool $is_end_user
 * @property bool $is_property_custodian
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['employee_no', 'name', 'position', 'office', 'is_inspector', 'is_end_user', 'is_property_custodian', 'is_active'])]
class Personnel extends Model
{
    /** @use HasFactory<PersonnelFactory> */
    use Filterable, HasFactory;

    /** The table name is "personnel" (not the plural "personnels"). */
    protected $table = 'personnel';

    /** Role name => column. The names come from PersonnelDirectory::ROLES. */
    public const ROLE_COLUMNS = [
        PersonnelDirectory::ROLE_INSPECTOR => 'is_inspector',
        PersonnelDirectory::ROLE_END_USER => 'is_end_user',
        PersonnelDirectory::ROLE_PROPERTY_CUSTODIAN => 'is_property_custodian',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_inspector' => 'boolean',
            'is_end_user' => 'boolean',
            'is_property_custodian' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Filter hook used by Filterable::scopeFilter (key: "search"). Matches name and employee no.
     *
     * @param  Builder<Personnel>  $query
     */
    public function filterBySearch(Builder $query, mixed $value): void
    {
        $like = '%'.addcslashes((string) $value, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('employee_no', 'like', $like);
        });
    }

    /**
     * Filter hook (key: "role"). An unknown role matches nobody: it must never widen the list.
     *
     * @param  Builder<Personnel>  $query
     */
    public function filterByRole(Builder $query, mixed $value): void
    {
        $column = self::ROLE_COLUMNS[(string) $value] ?? null;

        if ($column === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where($column, true);
    }
}