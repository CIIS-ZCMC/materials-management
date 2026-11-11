<?php

namespace App\Providers;

use App\Contracts\UserRepositoryInterface;
use App\Models\User;
use App\Repositories\UserRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            UserRepositoryInterface::class,
            UserRepository::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->recordLastLogin();
        $this->registerSectionGates();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Stamp last_login_at after a successful login.
     */
    protected function recordLastLogin(): void
    {
        Event::listen(Login::class, function (Login $event) {
            $event->user->forceFill(['last_login_at' => now()])->save();
        });
    }

    /**
     * One Gate per menu section, from config/mms_access.php ("sections").
     * A user may open a section if they hold ANY of its permissions.
     * Routes use it as middleware: can:section.deliveries
     */
    protected function registerSectionGates(): void
    {
        foreach (config('mms_access.sections') as $section => $permissions) {
            Gate::define(
                "section.{$section}",
                fn (User $user) => $user->hasAnyPermission($permissions),
            );
        }
    }
}