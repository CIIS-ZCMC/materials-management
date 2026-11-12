<?php

namespace App\Models;

use App\Traits\Filterable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string|null $employee_no
 * @property string $name
 * @property string|null $position
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['employee_no', 'name', 'position', 'email', 'password', 'is_active', 'last_login_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Filterable, HasFactory, HasRoles, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Readable name for the audit log, e.g. "Juan Cruz (DUMMY-0004)".
     * Used by App\Support\Audit.
     */
    public function auditLabel(): string
    {
        return $this->name.' ('.($this->employee_no ?: '#'.$this->id).')';
    }

    /**
     * Filter hook used by Filterable::scopeFilter (key: "search").
     * Matches employee no., name, position, and email.
     *
     * @param  Builder<User>  $query
     */
    public function filterBySearch(Builder $query, mixed $value): void
    {
        $like = '%'.addcslashes((string) $value, '%_\\').'%';

        $query->where(function (Builder $q) use ($like) {
            $q->where('employee_no', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('position', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }
}