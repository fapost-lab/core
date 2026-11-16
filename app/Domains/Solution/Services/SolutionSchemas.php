<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\Contracts\SolutionActivationStoreInterface;
use App\Domains\Solution\Contracts\SolutionMigratorInterface;
use App\Domains\Solution\Contracts\SolutionSchemaStoreInterface;
use App\Domains\Solution\Exceptions\SolutionMigrationFailedException;
use App\Domains\Solution\Exceptions\SolutionNotInstalledException;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * Brings a Solution's tables in the current tenant's schema up to the installed package. Run it inside the tenant's
 * context. The one caller of {@see SolutionMigratorInterface}: activation, the queued preparation of a granted
 * Solution, and `solutions:migrate` (which `platform:update` runs) all go through here.
 *
 * Tables appear only where a Solution runs and are kept current only where it is on or already has them: never in
 * every tenant at once, so an upgrade does not grow with the number of tenants that never used the Solution.
 */
final class SolutionSchemas
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly InstalledSolutions $installed,
        private readonly SolutionMigratorInterface $migrator,
        private readonly SolutionSchemaStoreInterface $store,
        private readonly ActiveSolutions $active,
        private readonly SolutionActivationStoreInterface $activations,
    ) {
    }

    /**
     * @return list<string> the migrations applied now; empty when the schema was already current
     *
     * @throws SolutionNotInstalledException when the Solution is not installed with a valid manifest
     * @throws SolutionMigrationFailedException when a migration fails; nothing of the run is kept
     */
    public function bringCurrent(string $solutionId): array
    {
        $solution = $this->installed->find($solutionId) ?? throw SolutionNotInstalledException::forId($solutionId);

        if ($this->active->isReady($solutionId)) {
            return [];
        }

        try {
            return $this->migrator->migrate($solution);
        } finally {
            $this->active->forget($this->tenantContext->get()->getId());
        }
    }

    /**
     * Installed Solutions an upgrade keeps current in this schema: those whose migrations ran here before (activated,
     * deactivated since, or granted and prepared), and those the tenant stored as active, so a Solution that gains
     * migrations in a new version is ready for its tenants right after the upgrade. Both are read from the schema
     * itself; the operator is never asked, so a granted Solution without a footprint is left to its queued preparation.
     *
     * @return list<string> sorted
     */
    public function idsToKeepCurrent(): array
    {
        $ids = array_values(array_unique(array_filter(
            [...array_keys($this->store->appliedDigests()), ...$this->activations->activeIds()],
            $this->installed->has(...),
        )));
        sort($ids);

        return $ids;
    }
}
