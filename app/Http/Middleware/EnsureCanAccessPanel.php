<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Staff\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for the Inertia console: answers 403 when the signed-in user may not use the
 * console (status not Active, or deactivated), on every request, as Filament's own
 * authentication middleware does for its panels. Run it after `auth`.
 */
final class EnsureCanAccessPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->canAccessConsole()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
