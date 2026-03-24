<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class TenancyMiddleware
{
    public function __construct(
        private TenantResolverInterface $resolver,
        private TenantSwitcher $tenantSwitcher,
        private CoreBootstrapInterface $coreBootstrap,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        return $this->tenantSwitcher->runForTenant($tenant, function () use ($next, $request): Response {
            $this->coreBootstrap->boot();

            try {
                return $next($request);
            } finally {
                $this->coreBootstrap->reset();
            }
        });
    }
}
