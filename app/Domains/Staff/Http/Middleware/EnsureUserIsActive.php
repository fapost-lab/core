<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks access for staff users whose account has been deactivated (is_active = false).
 * Checked on every authenticated request — an active session does not bypass this guard.
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! Auth::user()->is_active) {
            Auth::logout();

            abort(401, __('Your account has been deactivated. Please contact an administrator.'));
        }

        return $next($request);
    }
}
