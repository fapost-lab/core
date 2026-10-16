<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use App\Domains\Solution\Exceptions\SolutionEntitlementUnavailableException;
use App\Domains\Solution\Exceptions\SolutionGrantedException;
use App\Domains\Solution\Exceptions\SolutionNotEntitledException;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Solution\DTO\SolutionGrant;

/**
 * Turns a Solution on or off for the current tenant. Run it inside the tenant's context.
 *
 * Both calls are idempotent and drop the tenant's memo whatever they changed. Activation is refused for a Solution the
 * operator does not entitle the tenant to, whoever asks, and while the operator's answer cannot be had (fail closed: a
 * person is there to try again). A Solution the operator grants (its plan's bundle, a running add-on) runs with nothing
 * stored: activating it writes nothing and answers Unchanged, and deactivating it is refused, because only the
 * operator ends a grant. Deactivation leaves the Solution's data alone, needs no entitlement, and is allowed for a
 * Solution that is no longer installed so a stale row can be put out.
 */
final class SolutionActivator
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly SolutionActivationStoreInterface $store,
        private readonly InstalledSolutions $installed,
        private readonly ActiveSolutions $active,
        private readonly SolutionEntitlements $entitlements,
    ) {
    }

    /**
     * @throws SolutionNotInstalledException when the Solution is not installed with a valid manifest
     * @throws SolutionNotEntitledException when the operator does not entitle the tenant to it
     * @throws SolutionEntitlementUnavailableException when the operator's answer could not be had
     */
    public function activate(string $solutionId, ActivationActor $actor): ActivationChange
    {
        if (!$this->installed->has($solutionId)) {
            throw SolutionNotInstalledException::forId($solutionId);
        }

        $entitlement = $this->entitlements->forTenant($this->tenantContext->get()->getId());

        if (null === $entitlement) {
            throw SolutionEntitlementUnavailableException::forId($solutionId);
        }

        if (!$entitlement->allows($solutionId)) {
            throw SolutionNotEntitledException::forId($solutionId);
        }

        if ($entitlement->isGranted($solutionId)) {
            return ActivationChange::Unchanged;
        }

        return $this->apply($solutionId, SolutionActivationStatus::Active, $actor, null);
    }

    /**
     * @throws SolutionGrantedException when the operator grants the tenant the Solution, or granted it in its last answer while it cannot answer now
     */
    public function deactivate(string $solutionId, ActivationActor $actor, ?string $reason = null): ActivationChange
    {
        $tenantId    = $this->tenantContext->get()->getId();
        $entitlement = $this->entitlements->forTenant($tenantId);
        $granted     = null === $entitlement
            // While the operator cannot answer, a Solution running from its last grants is still the operator's to end.
            ? in_array($solutionId, array_map(static fn (SolutionGrant $grant): string => $grant->solutionId, $this->entitlements->lastGrantsFor($tenantId)), true)
            : $entitlement->isGranted($solutionId);

        if ($granted) {
            throw SolutionGrantedException::forId($solutionId);
        }

        return $this->apply($solutionId, SolutionActivationStatus::Inactive, $actor, $reason);
    }

    private function apply(string $solutionId, SolutionActivationStatus $target, ActivationActor $actor, ?string $reason): ActivationChange
    {
        $tenantId = $this->tenantContext->get()->getId();
        $change   = $this->store->change($solutionId, $target, $actor, $reason);

        // Also after an Unchanged: another process may have changed the row, and this scope's memo is then stale.
        $this->active->forget($tenantId);

        return $change;
    }
}
