<?php

declare(strict_types=1);

namespace App\Console\Platform;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the same two commands the upgrade guide has always listed.
 */
final class ArtisanPlatformMigrator implements PlatformMigratorInterface
{
    public function migrate(OutputInterface $output): bool
    {
        if (0 !== Artisan::call('migrate', ['--database' => 'landlord', '--force' => true], $output)) {
            return false;
        }

        return 0 === Artisan::call('ops:tenants-migrate', [], $output);
    }
}
