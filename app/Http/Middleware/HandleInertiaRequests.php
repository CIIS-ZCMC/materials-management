<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        // Computed first, so withoutRelations() below can drop the roles and
        // permissions Spatie just loaded (keeps auth.user small).
        $permissions = $user ? $user->getAllPermissions()->pluck('name')->values()->all() : [];
        $sections = $user ? $this->sectionsFor($user) : [];

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user?->withoutRelations(),
                'permissions' => $permissions, // e.g. "po.review", for buttons and fields
                'sections' => $sections,       // e.g. "deliveries", for the sidebar
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Names of the menu sections this user may open.
     *
     * @return array<int, string>
     */
    private function sectionsFor(User $user): array
    {
        return collect(array_keys(config('mms_access.sections')))
            ->filter(fn (string $section) => $user->can("section.{$section}"))
            ->values()
            ->all();
    }
}