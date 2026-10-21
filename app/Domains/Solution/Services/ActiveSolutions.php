<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;

/**
 * Which Solutions are active for the current tenant. The only reader of activation.
 *
 * A Solution is active when it is installed in this image with a valid manifest ({@see InstalledSolutions}) AND
 * either the operator grants it (a plan's bundle, a running add-on: it runs with nothing stored) OR the tenant's
 * stored status is active and the operator allows it ({@see SolutionEntitlements}). Losing the package, the grant or
 * the entitlement makes it inactive without a write; getting it back makes it active again. A read of the tenant's
 * state never writes. When the operator's answer cannot be had (fail open), what the tenant turned on keeps running,
 * and so does what the operator's last answer granted, for at most a day; nothing else can start.
 *
 * Bound `scoped`: the container drops the instance before every queue job and with every request, so a
 * deactivation reaches the next job of any process with no invalidation between processes. Inside one job
 * the tenant can change (`runForTenant()`), so the memo is keyed by tenant id and one tenant's answer is
 * never given to another. It is filled lazily, with one indexed query on the first question about a tenant;
 * the operator is asked whenever a Solution is installed, because a grant needs no stored row.
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
        $installed = array_map(static fn (InstalledSolution $solution): string => $solution->id, $this->installed->all());

        if ([] === $installed) {
            return [];
        }

        $tenantId    = $this->tenantContext->get()->getId();
        $stored      = array_values(array_filter($this->storedFor($tenantId), $this->installed->has(...)));
        $entitlement = $this->entitlements->forTenant($tenantId);

        if (null === $entitlement) {
            $granted = array_map(static fn (SolutionGrant $grant): string => $grant->solutionId, $this->entitlements->lastGrantsFor($tenantId));
            $ids     = [...$stored, ...array_intersect($granted, $installed)];
        } else {
            $ids = [
                ...array_filter($installed, $entitlement->isGranted(...)),
                ...array_filter($stored, $entitlement->allows(...)),
            ];
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * How the operator grants the Solution to the current tenant (its plan's bundle or a running add-on), or null when
     * it does not, or when its answer could not be had.
     */
    public function grantFor(string $solutionId): ?SolutionGrant
    {
        return $this->entitlement()?->grantFor($solutionId);
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
     * Drops the tenant's memo and the operator's answer for it, so the next question asks again: after a connect.
     */
    public function forgetEntitlement(string $tenantId): void
    {
        $this->forget($tenantId);
        $this->entitlements->forget($tenantId);
    }

    /**
     * @return list<string>
     */
    private function storedFor(string $tenantId): array
    {
        return $this->stored[$tenantId] ??= $this->store->activeIds();
    }
}
