<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Onboarding\Services\OnboardingProgress;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\CurrentAccessState;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the console of a tenant in the first-run setup wizard until the wizard is over.
 *
 * Route middleware of the `admin` stack (so `console` and the Solution stacks too), the builder and the media
 * routes; run it after authentication. It only reads the state: the wizard is the one writer, so a request that arrives
 * while a step is being saved cannot decide anything. In order, a request passes when:
 *
 * 1. it is not a page read (not GET or HEAD), or it is a JSON call that is not an Inertia visit (the bell, search);
 * 2. the user is the platform support user (an operator looks, and sees a banner instead);
 * 3. the tenant is stopped (the stop notice and the write refusal are the operator's say, not the wizard's);
 * 4. the wizard is not pending (the whole cost of the gate for an onboarded tenant is this one read);
 * 5. the route is the wizard's own, the sign-out, the interface language, or one a step lists in `routes()`.
 *
 * 6. every required step counts complete but a check was uncertain (it threw): nothing is written and the request
 *    passes, the state staying pending so the next request asks again.
 *
 * Progress is evaluated only for a pending tenant and only for a route outside the wizard. Anything else is sent to the wizard; an Inertia visit gets the location response, which the client follows with a
 * full page load, as the redirect of a plain request does.
 */
final readonly class EnsureTenantOnboarded
{
    /**
     * Route names let through besides the wizard's own and the steps'.
     *
     * @var list<string>
     */
    private const array ALWAYS_ALLOWED = ['console.auth.logout', 'console.locale.update'];

    private const string WIZARD_PREFIX = 'console.admin.onboarding.';

    public function __construct(
        private CurrentAccessState $accessState,
        private OnboardingState $state,
        private OnboardingProgress $progress,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || $user->isPlatformSupport()) {
            return $next($request);
        }

        if ($this->accessState->get()->isStopped() || ! $this->state->isPending()) {
            return $next($request);
        }

        if ($this->isAllowed((string) $request->route()?->getName())) {
            return $next($request);
        }

        // Every required step counts complete but a check could not be made: the wizard cannot close on a guess, and
        // holding the console for a broken check would lock the tenant out. Nothing is written; the next request asks again.
        $report = $this->progress->report();

        if ($report->hasUncertain() && null === $report->firstIncompleteRequired()) {
            return $next($request);
        }

        return Inertia::location(route('console.admin.onboarding.show'));
    }

    private function isAllowed(string $routeName): bool
    {
        if ('' === $routeName) {
            return false;
        }

        return str_starts_with($routeName, self::WIZARD_PREFIX)
            || in_array($routeName, self::ALWAYS_ALLOWED, true)
            || in_array($routeName, $this->progress->allowedRoutes(), true);
    }
}
