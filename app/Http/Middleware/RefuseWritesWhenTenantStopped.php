<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\CurrentAccessState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for the tenant's own endpoints outside Filament (the builder, media, the
 * mini app): while the tenant is stopped, a request that changes something is answered
 * 423 Locked with the notice the operator wrote. Reading carries on.
 *
 * The platform support user passes: an operator in a stopped tenant is there to look and, when
 * need be, to fix. A route with no side effects despite its method opts out with
 * `withoutMiddleware()`. Filament is not covered (no read-only mode there; the banner is
 * its only sign), and neither are logout, `/support/*` and activation, which sit outside
 * the routes this is applied to.
 */
final readonly class RefuseWritesWhenTenantStopped
{
    public function __construct(
        private CurrentAccessState $accessState,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->accessState->get();

        if (! $state->isStopped() || $request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && $user->isPlatformSupport()) {
            return $next($request);
        }

        $notice = $state->notice;

        return response()->json([
            'error'   => 'tenant_stopped',
            'message' => $notice?->message ?? $notice?->title ?? __('tenancy.stopped.message'),
            'notice'  => null === $notice ? null : [
                'title'       => $notice->title,
                'message'     => $notice->message,
                'actionLabel' => $notice->actionLabel,
                'actionUrl'   => $notice->actionUrl,
            ],
        ], Response::HTTP_LOCKED);
    }
}
