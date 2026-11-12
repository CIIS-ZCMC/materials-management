<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * Writes login, failed login and logout to the audit log.
 * Registered with Event::subscribe() in AppServiceProvider. The methods are
 * named on* (not handle*) on purpose: Laravel auto-registers handle* methods
 * in app/Listeners, which would log every event twice.
 * Passwords are never read or stored.
 */
class AuditAuthEvents
{
    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Logout::class => 'onLogout',
        ];
    }

    public function onLogin(Login $event): void
    {
        // Laravel also fires Login when a "remember me" cookie restores a
        // session. Only a real form login (POST /login) is an audit entry.
        if (! (request()->isMethod('POST') && request()->is('login'))) {
            return;
        }

        if (! $event->user instanceof Model) {
            return;
        }

        Audit::log('login', $event->user, 'Logged in', causer: $event->user, log: 'auth');
    }

    public function onFailed(Failed $event): void
    {
        $field = Fortify::username();
        $employeeNo = Str::limit(trim((string) ($event->credentials[$field] ?? '')), 50, '');

        $user = $event->user instanceof Model
            ? $event->user
            : ($employeeNo !== '' ? User::where('employee_no', $employeeNo)->first() : null);

        Audit::log(
            event: 'login_failed',
            subject: $user,
            description: 'Failed login attempt for employee no. '.($employeeNo !== '' ? $employeeNo : '(blank)'),
            extra: ['attempted_employee_no' => $employeeNo],
            log: 'auth',
        );
    }

    public function onLogout(Logout $event): void
    {
        // The EnsureUserIsActive middleware logs its own "session ended" entry.
        if (request()->attributes->get('audit.skip_logout')) {
            return;
        }

        if (! $event->user instanceof Model) {
            return;
        }

        Audit::log('logout', $event->user, 'Logged out', causer: $event->user, log: 'auth');
    }
}