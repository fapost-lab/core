<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

/**
 * Tenant-scoped second-stage bootstrap after {@see TenantContextInterface} is set.
 */
interface CoreBootstrapInterface
{
    /**
     * Runs domain bootstrap for the current tenant context.
     *
     * Guarantees:
     * - At most one effective bootstrap per distinct tenant id until {@see reset()}.
     *
     * Constraint:
     * - Caller must have resolved tenant context ({@see TenantContextInterface::get()} must succeed).
     */
    public function boot(): void;

    /**
     * Forgets bootstrap state so the next {@see boot()} runs again (e.g. next request or tenant change).
     */
    public function reset(): void;
}
