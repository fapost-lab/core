<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\CurrentAccessState;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for the tenant's own endpoints (the console, the builder, media, the
 * mini app): while the tenant is stopped, a request that changes something is answered
 * 423 Locked with the notice the operator wrote. Reading carries on.
 *
 * The platform support user passes: an operator in a stopped tenant is there to look and, when
 * need be, to fix. A route with no side effects despite its method opts out with
 * `withoutMiddleware()`. Logout, `/support/*` and activation are not covered: they sit outside
 * the routes this is applied to.
 */
final readonly class RefuseWritesWhenTenantStopped
{
    public function __construct(
        private CurrentAccessState $accessState,
    ) {
    }

    /**
     * Whether this user's changes are refused right now: the tenant is stopped and the user is not
     * the platform support user. The builder page asks the same question to open view-only.
     */
    public function refusesWritesFor(?Authenticatable $user): bool
    {
        if (! $this->accessState->get()->isStopped()) {
            return false;
        }

        return ! ($user instanceof User && $user->isPlatformSupport());
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! $this->refusesWritesFor($request->user())) {
            return $next($request);
        }

        $notice = $this->accessState->get()->notice;

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
