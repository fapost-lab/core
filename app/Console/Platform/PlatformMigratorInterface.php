<?php

declare(strict_types=1);

namespace App\Console\Platform;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The database half of a platform update, one step per call so that `platform:update` can say which step failed and
 * what it still runs after it: the landlord schema, every tenant schema, then each Solution's tables.
 *
 * Migrations only go up. A failed step leaves what earlier steps applied in place; a Solution's run in one tenant
 * schema is a single transaction, so its failure leaves that schema as it was for that Solution and nothing else.
 */
interface PlatformMigratorInterface
{
    /**
     * @return bool true when every landlord migration ran
     */
    public function migrateLandlord(OutputInterface $output): bool;

    /**
     * @return bool true when every tenant schema migrated; a failed tenant does not stop the others
     */
    public function migrateTenants(OutputInterface $output): bool;

    /**
     * Brings each Solution's tables current in the tenant schemas that keep it, one Solution at a time.
     *
     * @param  list<string>  $solutionIds  installed Solutions that ship migrations
     *
     * @return list<string> the ids that failed in at least one tenant, in the order given
     */
    public function migrateSolutions(array $solutionIds, OutputInterface $output): array;
}
