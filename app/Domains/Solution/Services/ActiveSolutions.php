<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * Which Solutions are active for the current tenant. The only reader of activation.
 *
 * A Solution is active when the tenant's stored status is active AND the Solution is installed in this
 * image with a valid manifest ({@see InstalledSolutions}). Losing the package makes it inactive without a
 * write; getting it back makes it active again where the tenant had left it on. A read never writes.
 *
 * Bound `scoped`: the container drops the instance before every queue job and with every request, so a
 * deactivation reaches the next job of any process with no invalidation between processes. Inside one job
 * the tenant can change (`runForTenant()`), so the memo is keyed by tenant id and one tenant's answer is
 * never given to another. It is filled lazily, with one indexed query on the first question about a tenant.
 */
final class ActiveSolutions
{
    /** @var array<string, list<string>> stored active ids by tenant id; not yet intersected with the installed Solutions */
    private array $stored = [];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly SolutionActivationStoreInterface $store,
        private readonly InstalledSolutions $installed,
    ) {
    }

    public function isActive(string $solutionId): bool
    {
        return in_array($solutionId, $this->ids(), true);
    }

    /**
     * @return list<string> sorted
     */
    public function ids(): array
    {
        $stored = $this->storedFor($this->tenantContext->get()->getId());

        return array_values(array_filter($stored, $this->installed->has(...)));
    }

    /**
     * Drops the memo of one tenant. The activator calls it after a write.
     */
    public function forget(string $tenantId): void
    {
        unset($this->stored[$tenantId]);
    }

    /**
     * @return list<string>
     */
    private function storedFor(string $tenantId): array
    {
        return $this->stored[$tenantId] ??= $this->store->activeIds();
    }
}
