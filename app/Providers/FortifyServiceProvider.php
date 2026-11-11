<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        // Log in with employee number; block deactivated accounts
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('employee_no', $request->input('employee_no'))->first();

            if (! $user || ! Hash::check($request->input('password'), $user->password)) {
                return null; // normal "credentials don't match" error
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    'employee_no' => 'This account is deactivated. Please contact the administrator.',
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views. Only the login page is used.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'status' => $request->session()->get('status'),
        ]));
    }

    /**
     * Configure rate limiting (5 login attempts per minute per employee number and IP).
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}