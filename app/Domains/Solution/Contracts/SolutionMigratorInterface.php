<?php

declare(strict_types=1);

namespace App\Domains\Solution\Contracts;

use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\Exceptions\SolutionMigrationFailedException;

/**
 * Applies a Solution's tenant migrations in the schema the caller is switched into.
 *
 * Every pending migration of the Solution, its journal entries and its footprint are written in one transaction:
 * PostgreSQL DDL is transactional, so a failure leaves the schema as it was. Migrations only ever go up; a
 * Solution's `down()` is never called.
 */
interface SolutionMigratorInterface
{
    /**
     * @return list<string> names of the migrations applied now, oldest first; empty when nothing was pending
     *
     * @throws SolutionMigrationFailedException when a migration fails or is not one Core runs
     */
    public function migrate(InstalledSolution $solution): array;
}
