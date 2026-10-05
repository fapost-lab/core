<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Services\TenantRequestRunner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The `tenant` alias: a tenant is required here.
 *
 * Inside the `web` group the tenant is already active ({@see ResolveTenantContext}) and
 * this passes straight through. Routes outside that group (Filament panels, tenant
 * routes) enter the tenant here instead. A request on a host that names no tenant,
 * such as the base domain, is answered with 404.
 */
final readonly class TenancyMiddleware
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private RequestHostClassifier $classifier,
        private TenantRequestRunner $runner,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenantContext->isResolved()) {
            return $next($request);
        }

        if (! $this->classifier->classify($request)->isTenant()) {
            throw new NotFoundHttpException();
        }

        return $this->runner->run($request, $next);
    }
}
