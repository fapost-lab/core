<?php

declare(strict_types=1);

namespace App\Domains\Staff\Http\Middleware;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\SupportAccessSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a support session when it must no longer exist:
 *
 * - an hour after it began, whatever the activity;
 * - when support access is switched off (the emergency stop: flip the flag and every open support
 *   session ends on its next request);
 * - when the signed-in user is the platform support user but the session carries no support record
 *   (a session that did not come through the entry route).
 *
 * Runs after the session starts, in the `web` group and in both panels (as a persistent middleware, so
 * Livewire updates are checked too). The entry route itself is skipped: a fresh grant must not be
 * swallowed by an old support cookie, and the route replaces the session anyway.
 */
final class EndExpiredSupportSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || $request->routeIs('support.enter') || ! $this->mustEnd($request)) {
            return $next($request);
        }

        // The logout listener closes the entry.
        Auth::guard()->logout();
        $request->session()->invalidate();

        return $this->ended($request);
    }

    private function mustEnd(Request $request): bool
    {
        $support = new SupportAccessSession($request->session());

        if (null !== $support->current()) {
            return $support->isExpired() || true !== config('tenancy.support_access.enabled');
        }

        $user = Auth::guard()->user();

        return $user instanceof User && $user->isPlatformSupport();
    }

    private function ended(Request $request): Response
    {
        $login = route('filament.admin.auth.login');

        // Livewire reloads the page on 419, which lands on the login form.
        if ($request->headers->has('X-Livewire')) {
            abort(419);
        }

        // An Inertia visit follows a full-page redirect to the login form.
        if ($request->headers->has('X-Inertia')) {
            return Inertia::location($login);
        }

        if ($request->expectsJson()) {
            abort(401);
        }

        return redirect()->to($login);
    }
}
