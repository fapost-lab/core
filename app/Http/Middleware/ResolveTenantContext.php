<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Services\TenantRequestRunner;
use App\Domains\Tenancy\ValueObjects\RequestHostKind;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Prefix of the `web` group: puts the request in the tenant its host names, if any.
 *
 * The base domain carries platform pages and runs with no tenant; a tenant host runs
 * inside that tenant; any other host is not ours. Routes that cannot work without a
 * tenant say so with the `tenant` alias ({@see TenancyMiddleware}).
 */
final readonly class ResolveTenantContext
{
    public function __construct(
        private RequestHostClassifier $classifier,
        private TenantRequestRunner $runner,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return match ($this->classifier->classify($request)->kind) {
            RequestHostKind::Platform => $next($request),
            RequestHostKind::Tenant   => $this->runner->run($request, $next),
            RequestHostKind::Foreign  => throw new NotFoundHttpException(),
        };
    }
}
