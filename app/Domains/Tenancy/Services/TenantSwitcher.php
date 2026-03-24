<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Closure;

/**
 * Orchestrates tenant identity ({@see TenantContextInterface}) and tenant DB switching for a bounded scope.
 */
final readonly class TenantSwitcher
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private TenantDatabaseManagerInterface $databaseManager,
    ) {
    }

    /**
     * Switches runtime into tenant context for callback execution.
     *
     * Guarantees:
     * - Tenant context reflects the given tenant for the duration of the callback.
     * - Default connection targets that tenant’s schema (via {@see TenantDatabaseManagerInterface::switchTo()} stack).
     * - Context and DB stack are restored when the callback returns or throws.
     *
     * Nested calls restore the outer tenant context in order; do not call {@see TenantDatabaseManagerInterface::switchTo()}
     * without a matching {@see TenantDatabaseManagerInterface::restore()}.
     */
    public function runForTenant(TenantInterface $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenantContext->isResolved()
            ? $this->tenantContext->get()
            : null;

        $this->tenantContext->set($tenant);
        $this->databaseManager->switchTo($tenant);

        try {
            return $callback();
        } finally {
            $this->databaseManager->restore();

            if (null !== $previousTenant) {
                $this->tenantContext->set($previousTenant);
            } else {
                $this->tenantContext->reset();
            }
        }
    }
}
