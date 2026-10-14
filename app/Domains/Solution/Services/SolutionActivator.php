<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Enums\SolutionActivationStatus;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * Turns a Solution on or off for the current tenant. Run it inside the tenant's context.
 *
 * Both calls are idempotent and drop the tenant's memo whatever they changed. Deactivation leaves the Solution's data alone, and is allowed for a Solution
 * that is no longer installed so a stale row can be put out.
 */
final class SolutionActivator
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly SolutionActivationStoreInterface $store,
        private readonly InstalledSolutions $installed,
        private readonly ActiveSolutions $active,
    ) {
    }

    /**
     * @throws SolutionNotInstalledException when the Solution is not installed with a valid manifest
     */
    public function activate(string $solutionId, ActivationActor $actor): ActivationChange
    {
        if (!$this->installed->has($solutionId)) {
            throw SolutionNotInstalledException::forId($solutionId);
        }

        return $this->apply($solutionId, SolutionActivationStatus::Active, $actor, null);
    }

    public function deactivate(string $solutionId, ActivationActor $actor, ?string $reason = null): ActivationChange
    {
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
