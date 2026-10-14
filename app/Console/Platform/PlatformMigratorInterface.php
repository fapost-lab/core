<?php

declare(strict_types=1);

namespace App\Console\Platform;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The database half of a platform update: the landlord schema, then every tenant schema.
 */
interface PlatformMigratorInterface
{
    /**
     * @return bool true when every migration ran
     */
    public function migrate(OutputInterface $output): bool;
}
