<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResetUserPasswordRequest;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Models\User;
use App\Services\UserService;
use App\Support\MmsAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', ''));
        $paginator = $this->service->paginate(['search' => $search]);

        $presets = MmsAccess::presets();
        $basePermissions = MmsAccess::rolePermissions(MmsAccess::BASE_ROLE);

        return Inertia::render('admin/users/index', [
            'users' => [
                'data' => $paginator->getCollection()
                    ->map(fn (User $user) => $this->row($user, $presets, $basePermissions))
                    ->values(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
            'filters' => ['search' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('admin/users/create', [
            'access' => MmsAccess::formOptions(),
            'canManageAccess' => $request->user()->can('manageAccess', User::class),
        ]);
    }

    public function store(SaveUserRequest $request): RedirectResponse
    {
        $this->service->create(
            $request->validated(),
            $request->user()->can('manageAccess', User::class),
        );

        return to_route('admin.users.index')->with('success', 'User created.');
    }

    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        $user->load(['roles:id,name', 'permissions:id,name']);

        return Inertia::render('admin/users/edit', [
            'user' => [
                'id' => $user->id,
                'employee_no' => $user->employee_no,
                'name' => $user->name,
                'position' => $user->position,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'role' => $user->roles->first()?->name ?? MmsAccess::BASE_ROLE,
                'direct_permissions' => $user->permissions->pluck('name')->values(),
            ],
            'access' => MmsAccess::formOptions(),
            'canManageAccess' => $request->user()->can('manageAccess', User::class),
        ]);
    }

    public function update(SaveUserRequest $request, User $user): RedirectResponse
    {
        $this->service->update(
            $request->user(),
            $user,
            $request->validated(),
            $request->user()->can('manageAccess', User::class),
        );

        return to_route('admin.users.index')->with('success', 'User updated.');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('toggleActive', $user);

        $active = $request->boolean('active');
        $this->service->setActive($request->user(), $user, $active);

        return back()->with('success', $active ? 'User activated.' : 'User deactivated.');
    }

    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->service->resetPassword($user, $request->validated('password'));

        return back()->with('success', 'Password reset.');
    }

    /**
     * @param  list<array{key: string, label: string, permissions: list<string>}>  $presets
     * @param  list<string>  $basePermissions
     * @return array<string, mixed>
     */
    private function row(User $user, array $presets, array $basePermissions): array
    {
        $role = $user->roles->first()?->name;

        return [
            'id' => $user->id,
            'employee_no' => $user->employee_no,
            'name' => $user->name,
            'position' => $user->position,
            'email' => $user->email,
            'role' => $role,
            'job' => $role === MmsAccess::BASE_ROLE
                ? MmsAccess::jobLabel($user->permissions->pluck('name')->all(), $presets, $basePermissions)
                : null,
            'is_active' => $user->is_active,
        ];
    }
}