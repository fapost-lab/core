<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;

/**
 * Which Solutions are active for the current tenant. The only reader of activation.
 *
 * A Solution is active when the tenant's stored status is active AND the Solution is installed in this
 * image with a valid manifest ({@see InstalledSolutions}) AND the operator entitles the tenant to it
 * ({@see SolutionEntitlements}). Losing the package or the entitlement makes it inactive without a write;
 * getting it back makes it active again where the tenant had left it on. A read never writes. When the
 * operator's answer cannot be had, the entitlement factor is left out (fail open): what the tenant
 * turned on keeps running, and nothing it did not turn on can start.
 *
 * Bound `scoped`: the container drops the instance before every queue job and with every request, so a
 * deactivation reaches the next job of any process with no invalidation between processes. Inside one job
 * the tenant can change (`runForTenant()`), so the memo is keyed by tenant id and one tenant's answer is
 * never given to another. It is filled lazily, with one indexed query on the first question about a tenant;
 * the operator is asked only when the tenant has an installed Solution stored as active.
 */
final class ActiveSolutions
{
    /** @var array<string, list<string>> stored active ids by tenant id; not yet intersected with the installed Solutions */
    private array $stored = [];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly SolutionActivationStoreInterface $store,
        private readonly InstalledSolutions $installed,
        private readonly SolutionEntitlements $entitlements,
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
        $tenantId = $this->tenantContext->get()->getId();
        $ids      = array_values(array_filter($this->storedFor($tenantId), $this->installed->has(...)));

        if ([] === $ids) {
            return [];
        }

        $entitlement = $this->entitlements->forTenant($tenantId);

        if (null === $entitlement || $entitlement->isUnrestricted()) {
            return $ids;
        }

        return array_values(array_filter($ids, $entitlement->allows(...)));
    }

    /**
     * Whether the operator entitles the current tenant to the Solution, for the screens and commands that say why a
     * Solution does not run. Asks the operator (once per scope); null when its answer could not be had.
     */
    public function isEntitled(string $solutionId): ?bool
    {
        return $this->entitlement()?->allows($solutionId);
    }

    /**
     * The operator's answer for the current tenant, with the button it offers for a Solution not included; null when
     * the answer could not be had.
     */
    public function entitlement(): ?SolutionEntitlement
    {
        return $this->entitlements->forTenant($this->tenantContext->get()->getId());
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
