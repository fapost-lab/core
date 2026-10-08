<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the rest of a request inside the tenant its host names.
 *
 * Shared by the two tenant middleware so both enter a tenant the same way: resolve,
 * switch the schema, boot the tenant runtime, note the tenant's access mode, and tear it all down
 * when the request ends.
 */
final readonly class TenantRequestRunner
{
    public function __construct(
        private TenantResolverInterface $resolver,
        private TenantSwitcher $tenantSwitcher,
        private CoreBootstrapInterface $coreBootstrap,
        private TenantAccessStates $accessStates,
        private CurrentAccessState $accessState,
    ) {
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function run(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        return $this->tenantSwitcher->runForTenant($tenant, function () use ($next, $request, $tenant): Response {
            $this->coreBootstrap->boot();

            try {
                // Asked once per request: the write guard, the banner and the shared props read it from here.
                $this->accessState->set($this->accessStates->stateFor($tenant->getId()));

                return $next($request);
            } finally {
                $this->accessState->clear();
                $this->coreBootstrap->reset();
            }
        });
    }
}
