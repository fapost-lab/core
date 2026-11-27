<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Console\Platform\PlatformMigratorInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A `platform:update` migrator that touches no database: it records what it was asked and fails the steps a test sets.
 */
final class FakePlatformMigrator implements PlatformMigratorInterface
{
    /** @var int how many times the landlord was migrated: once per update that got past validation */
    public int $landlordRuns = 0;

    public bool $landlordSucceeds = true;

    public bool $tenantsSucceed = true;

    /** @var list<string> Solution ids whose pass fails */
    public array $failingSolutions = [];

    /** @var list<list<string>> the Solution ids each Solutions phase was asked to migrate */
    public array $solutionRuns = [];

    public function migrateLandlord(OutputInterface $output): bool
    {
        $this->landlordRuns++;

        return $this->landlordSucceeds;
    }

    public function migrateTenants(OutputInterface $output): bool
    {
        return $this->tenantsSucceed;
    }

    public function migrateSolutions(array $solutionIds, OutputInterface $output): array
    {
        $this->solutionRuns[] = $solutionIds;

        return array_values(array_intersect($solutionIds, $this->failingSolutions));
    }
}
