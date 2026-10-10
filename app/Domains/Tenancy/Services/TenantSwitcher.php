<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Closure;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Orchestrates tenant identity ({@see TenantContextInterface}) and tenant DB switching for a bounded scope.
 *
 * Consumers may register restore hooks via {@see registerRestoreHook()} to reset per-request context
 * that is not owned by this class. Hooks are invoked in the finally block before tenant context is restored.
 *
 * Context that must survive a nested switch (CurrentAssistant inside an HTTP request) registers a context hook
 * via {@see registerContextHook()} instead: it snapshots what it owns on entry, before the switch, and returns
 * the closure that puts it back on exit. Restore closures run in the reverse order of entry.
 *
 * The permission registrar is a singleton shared by every tenant a worker serves, and its cache
 * store is shared by every worker. Each switch therefore points the registrar at a cache key of
 * the tenant's own and drops the collection already loaded in memory; the cached entries of other
 * tenants stay where they are.
 */
final class TenantSwitcher
{
    /** @var array<int, Closure> */
    private array $restoreHooks = [];

    /** @var array<int, Closure(): Closure> */
    private array $contextHooks = [];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly TenantDatabaseManagerInterface $databaseManager,
        private readonly PermissionRegistrar $permissionRegistrar,
        private readonly string $permissionCacheKey = 'spatie.permission.cache',
    ) {
    }

    public function registerRestoreHook(Closure $hook): void
    {
        $this->restoreHooks[] = $hook;
    }

    /**
     * Registers a hook that runs on entry of every switch, before the tenant changes, and returns the closure
     * that runs on exit (in the `finally`, before the tenant context is restored). Use it for context that is
     * scoped to the caller and must be hidden inside the switch and returned to the caller after it.
     *
     * @param  Closure(): Closure  $enter
     */
    public function registerContextHook(Closure $enter): void
    {
        $this->contextHooks[] = $enter;
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

        $exits = [];

        try {
            foreach ($this->contextHooks as $enter) {
                $exits[] = $enter();
            }
        } catch (Throwable $exception) {
            // A hook that cannot enter leaves nothing switched yet; put back what the earlier ones took.
            try {
                $this->runAll(array_reverse($exits));
            } catch (Throwable) {
                // The entry failure is the one to see; a failed undo must not replace it.
            }

            throw $exception;
        }

        $this->tenantContext->set($tenant);
        $this->databaseManager->switchTo($tenant);
        $this->usePermissionCacheOf($tenant);

        try {
            return $callback();
        } finally {
            try {
                $this->databaseManager->restore();
            } finally {
                // A restore that fails (the database went away) must not leave
                // the tenant identity behind for the next job on this worker.
                try {
                    $this->usePermissionCacheOf($previousTenant);

                    $this->runAll([...$this->restoreHooks, ...array_reverse($exits)]);
                } finally {
                    if (null !== $previousTenant) {
                        $this->tenantContext->set($previousTenant);
                    } else {
                        $this->tenantContext->reset();
                    }
                }
            }
        }
    }

    /**
     * Runs every closure in order, each one even when an earlier one throws; the first failure is rethrown after
     * the rest have run.
     *
     * @param  list<Closure>  $closures
     */
    private function runAll(array $closures): void
    {
        $failure = null;

        foreach ($closures as $closure) {
            try {
                $closure();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Points the permission registrar at the cache of the given tenant, or at the platform key when
     * no tenant is active, and forgets what it has loaded in memory. The cache store itself is left
     * alone: spatie flushes the current key when roles or permissions change.
     */
    private function usePermissionCacheOf(?TenantInterface $tenant): void
    {
        $this->permissionRegistrar->cacheKey = null === $tenant
            ? $this->permissionCacheKey
            : $this->permissionCacheKey . '.tenant.' . $tenant->getId();

        $this->permissionRegistrar->clearPermissionsCollection();
    }
}
