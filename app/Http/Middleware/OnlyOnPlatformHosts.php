<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\ValueObjects\RequestHostKind;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Keeps an operations surface (Horizon, Telescope) off tenant hosts.
 *
 * In `host` mode every `<slug>.<base_domain>` serves a tenant, and a path registered without a
 * domain would answer there too: a tenant's browser would reach the queue dashboard of the whole
 * installation. Only the base domain and the declared platform subdomains may serve it; a
 * tenant host answers 404 as if the path did not exist. It sits ahead of `web`, so the refusal
 * happens before a tenant is entered. `single` mode has one host and nothing to separate.
 */
final readonly class OnlyOnPlatformHosts
{
    public function __construct(private RequestHostClassifier $classifier)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (TenancyResolutionMode::Host !== TenancyResolutionMode::fromConfig()) {
            return $next($request);
        }

        if (RequestHostKind::Platform !== $this->classifier->classify($request)->kind) {
            throw new NotFoundHttpException();
        }

        return $next($request);
    }
}
