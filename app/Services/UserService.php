<?php

namespace App\Services;

use App\Contracts\UserRepositoryInterface;
use App\Models\User;
use App\Support\MmsAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as AdminPaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class UserService extends BaseService
{
    public function __construct(
        protected UserRepositoryInterface $userRepository
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Existing methods (unchanged)
    |--------------------------------------------------------------------------
    */

    /**
     * Retrieve all users.
     *
     * @return Collection<int, User>
     */
    public function getAllUsers(): Collection
    {
        return $this->userRepository->all();
    }

    /**
     * Retrieve paginated users.
     *
     * @return LengthAwarePaginator<int, User>
     */
    public function getPaginatedUsers(int $perPage = 15): LengthAwarePaginator
    {
        return $this->userRepository->paginate($perPage);
    }

    /**
     * Find a user by ID.
     */
    public function getUserById(int|string $id): ?User
    {
        return $this->userRepository->findById($id);
    }

    /**
     * Find a user by email.
     */
    public function getUserByEmail(string $email): ?User
    {
        return $this->userRepository->findByEmail($email);
    }

    /**
     * Create a new user with hashed password.
     *
     * @param  array{name: string, email: string, password: string}  $data
     *
     * @throws Throwable
     */
    public function createUser(array $data): User
    {
        return $this->transaction(function () use ($data) {
            $data['password'] = Hash::make($data['password']);

            return $this->userRepository->create($data);
        });
    }

    /**
     * Update an existing user.
     *
     * @param  array{name?: string, email?: string, password?: string}  $data
     *
     * @throws Throwable
     */
    public function updateUser(int|string $id, array $data): bool
    {
        return $this->transaction(function () use ($id, $data) {
            if (isset($data['password']) && ! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            return $this->userRepository->update($id, $data);
        });
    }

    /**
     * Delete a user by ID.
     *
     * @throws Throwable
     */
    public function deleteUser(int|string $id): bool
    {
        return $this->transaction(function () use ($id) {
            return $this->userRepository->delete($id);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1.2: Admin user management
    |--------------------------------------------------------------------------
    | These use DB::transaction directly (not $this->transaction) so the
    | ValidationException thrown by the guards reaches the form untouched.
    */

    /**
     * @param  array<string, mixed>  $filters
     * @return AdminPaginator<int, User>
     */
    public function paginate(array $filters, int $perPage = 15): AdminPaginator
    {
        return $this->userRepository->paginateForAdmin($filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     * @param  bool  $withAccess  actor has roles.manage, so role/permissions are honoured
     */
    public function create(array $data, bool $withAccess): User
    {
        return DB::transaction(function () use ($data, $withAccess) {
            $user = new User(Arr::only($data, ['employee_no', 'name', 'position', 'email', 'password']));
            $user->is_active = true;
            $user->save();

            $role = $withAccess ? ($data['role'] ?? MmsAccess::BASE_ROLE) : MmsAccess::BASE_ROLE;
            $user->syncRoles([$role]);

            if ($withAccess) {
                $user->syncPermissions($this->directOnly($role, $data['permissions'] ?? []));
            }

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function update(User $actor, User $target, array $data, bool $withAccess): User
    {
        return DB::transaction(function () use ($actor, $target, $data, $withAccess) {
            $role = null;
            $direct = [];

            if ($withAccess) {
                $role = $data['role'] ?? $target->roles->first()?->name ?? MmsAccess::BASE_ROLE;
                $direct = $this->directOnly($role, $data['permissions'] ?? []);
                $this->guardAccessChange($actor, $target, $role, $direct);
            }

            $target->update(Arr::only($data, ['employee_no', 'name', 'position', 'email']));

            if ($withAccess && $role !== null) {
                $target->syncRoles([$role]);
                $target->syncPermissions($direct);
            }

            return $target;
        });
    }

    public function setActive(User $actor, User $target, bool $active): void
    {
        DB::transaction(function () use ($actor, $target, $active) {
            if (! $active) {
                if ($actor->is($target)) {
                    throw $this->fail('user', 'You cannot deactivate your own account.');
                }

                if ($target->is_active && $target->hasRole(MmsAccess::ADMIN_ROLE) && $this->userRepository->countActiveWithRole(MmsAccess::ADMIN_ROLE, true) <= 1) {
                    throw $this->fail('user', 'This is the last active Admin and cannot be deactivated.');
                }
            }

            $target->update(['is_active' => $active]);
        });
    }

    public function resetPassword(User $target, string $password): void
    {
        $target->forceFill([
            'password' => $password, // hashed by the model cast
            'remember_token' => Str::random(60),
        ])->save();
    }

    /**
     * @param  list<string>  $direct
     */
    private function guardAccessChange(User $actor, User $target, string $newRole, array $direct): void
    {
        $demoting = $target->hasRole(MmsAccess::ADMIN_ROLE) && $newRole !== MmsAccess::ADMIN_ROLE;

        if ($demoting && $actor->is($target)) {
            throw $this->fail('role', 'You cannot remove your own Admin role.');
        }

        if ($demoting && $target->is_active && $this->userRepository->countActiveWithRole(MmsAccess::ADMIN_ROLE, true) <= 1) {
            throw $this->fail('role', 'This is the last active Admin and cannot be demoted.');
        }

        if ($actor->is($target)) {
            $effective = array_merge(MmsAccess::rolePermissions($newRole), $direct);

            foreach (MmsAccess::SELF_KEEP as $required) {
                if (! in_array($required, $effective, true)) {
                    throw $this->fail('permissions', "You cannot remove your own {$required} permission.");
                }
            }
        }
    }

    /**
     * Direct permissions exclude anything the role already grants.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function directOnly(string $role, array $permissions): array
    {
        return array_values(array_diff(array_unique($permissions), MmsAccess::rolePermissions($role)));
    }

    private function fail(string $key, string $message): ValidationException
    {
        return ValidationException::withMessages([$key => $message]);
    }
}