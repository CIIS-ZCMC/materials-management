<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepositoryInterface<User>
 */
interface UserRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find a user by email address.
     */
    public function findByEmail(string $email): ?User;

    /**
     * Paginated list for the admin screen (roles and direct permissions loaded).
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginateForAdmin(array $filters, int $perPage = 15): LengthAwarePaginator;

    /**
     * Count active users holding a role. With $lock, rows are locked FOR UPDATE
     * so two admins cannot demote/deactivate the last two Admins at once.
     */
    public function countActiveWithRole(string $role, bool $lock = false): int;
}