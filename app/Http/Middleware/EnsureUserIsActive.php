<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who was deactivated while logged in.
 * The user is re-read from the database on every request, so the effect is
 * immediate: their next click sends them to the login page.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->is_active) {
            $request->attributes->set('audit.skip_logout', true);

            Audit::log(
                'session_ended',
                $user,
                "Session ended: {$user->auditLabel()} is deactivated",
                causer: $user,
                log: 'auth',
            );

            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login', 303)
                ->with('status', 'Your account has been deactivated. Please contact the administrator.');
        }

        return $next($request);
    }
}
