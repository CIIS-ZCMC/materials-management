<?php

namespace App\Providers;

use App\Contracts\ItemCategoryRepositoryInterface;
use App\Contracts\ItemRepositoryInterface;
use App\Contracts\PurchaseOrderProvider;
use App\Contracts\PurchaseOrderRepositoryInterface;
use App\Contracts\SupplierRepositoryInterface;
use App\Contracts\UnitRepositoryInterface;
use App\Contracts\UserRepositoryInterface;
use App\Integrations\PurchaseOrders\BizBoxPurchaseOrderProvider;
use App\Integrations\PurchaseOrders\LocalPurchaseOrderProvider;
use App\Listeners\AuditAuthEvents;
use App\Models\User;
use App\Policies\ActivityPolicy;
use App\Repositories\ItemCategoryRepository;
use App\Repositories\ItemRepository;
use App\Repositories\PurchaseOrderRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Activitylog\Models\Activity;

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

        $this->app->bind(
            SupplierRepositoryInterface::class,
            SupplierRepository::class,
        );

        $this->app->bind(
            ItemCategoryRepositoryInterface::class,
            ItemCategoryRepository::class,
        );

        $this->app->bind(
            UnitRepositoryInterface::class,
            UnitRepository::class,
        );

        $this->app->bind(
            ItemRepositoryInterface::class,
            ItemRepository::class,
        );

        $this->app->bind(
            PurchaseOrderRepositoryInterface::class,
            PurchaseOrderRepository::class,
        );

        // Step 3.4: where PO details come from. The BizBox provider is created
        // only when BIZBOX_ENABLED is true (config mms.bizbox.enabled).
        // bind() (not singleton) so the flag is read each time it is resolved.
        $this->app->bind(
            PurchaseOrderProvider::class,
            fn () => config('mms.bizbox.enabled')
                ? new BizBoxPurchaseOrderProvider
                : new LocalPurchaseOrderProvider,
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
        $this->registerAuditing();
    }

    /**
     * Audit trail: policy for the Audit Log page (audit.view) and the
     * login / failed login / logout listeners.
     */
    protected function registerAuditing(): void
    {
        Gate::policy(Activity::class, ActivityPolicy::class);

        Event::subscribe(AuditAuthEvents::class);
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