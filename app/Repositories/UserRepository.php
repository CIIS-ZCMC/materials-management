<?php

namespace App\Repositories;

use App\Contracts\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<User>
 */
class UserRepository extends BaseRepository implements UserRepositoryInterface
{
    public function __construct(User $user)
    {
        parent::__construct($user);
    }

    /**
     * Find a user by email address.
     */
    public function findByEmail(string $email): ?User
    {
        return $this->model->newQuery()->where('email', $email)->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return $this->model->newQuery()
            ->with(['roles:id,name', 'permissions:id,name'])
            ->filter($filters)
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function countActiveWithRole(string $role, bool $lock = false): int
    {
        $query = $this->model->newQuery()->role($role)->where('is_active', true);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->pluck('users.id')->count();
    }
}