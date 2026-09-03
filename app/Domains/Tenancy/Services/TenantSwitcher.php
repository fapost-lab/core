<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Orchestrates tenant identity ({@see TenantContextInterface}) and tenant DB switching for a bounded scope.
 *
 * Consumers may register restore hooks via {@see registerRestoreHook()} to reset per-request context
 * that is not owned by this class (e.g. CurrentAssistant). Hooks are invoked in the finally block
 * before tenant context is restored.
 */
final class TenantSwitcher
{
    /** @var array<int, Closure> */
    private array $restoreHooks = [];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantDatabaseManagerInterface $databaseManager,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {
    }

    public function registerRestoreHook(Closure $hook): void
    {
        $this->restoreHooks[] = $hook;
    }

    /**
     * Switches runtime into tenant context for callback execution.
     *
     * Guarantees:
     * - Tenant context reflects the given tenant for the duration of the callback.
     * - Default connection targets that tenant’s schema (via {@see TenantDatabaseManagerInterface::switchTo()} stack).
     * - Context and DB stack are restored when the callback returns or throws.
     *
     * Nested calls restore the outer tenant context in order; do not call
     * {@see TenantDatabaseManagerInterface::switchTo()} without a matching
     * {@see TenantDatabaseManagerInterface::restore()}.
     */
    public function runForTenant(TenantInterface $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenantContext->isResolved()
            ? $this->tenantContext->get()
            : null;

        $this->tenantContext->set($tenant);
        $this->databaseManager->switchTo($tenant);
        $this->permissionRegistrar->forgetCachedPermissions();

        try {
            return $callback();
        } finally {
            try {
                $this->databaseManager->restore();
            } finally {
                // A restore that fails (the database went away) must not leave
                // the tenant identity behind for the next job on this worker.
                $this->permissionRegistrar->forgetCachedPermissions();

                foreach ($this->restoreHooks as $hook) {
                    $hook();
                }

                if (null !== $previousTenant) {
                    $this->tenantContext->set($previousTenant);
                } else {
                    $this->tenantContext->reset();
                }
            }
        }
    }
}
