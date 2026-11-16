<?php

declare(strict_types=1);

namespace App\Console\Platform;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the two commands the upgrade guide has always listed, then brings the tables of the Solutions present in each
 * tenant's schema up to the installed packages.
 */
final class ArtisanPlatformMigrator implements PlatformMigratorInterface
{
    public function migrate(OutputInterface $output): bool
    {
        if (0 !== Artisan::call('migrate', ['--database' => 'landlord', '--force' => true], $output)) {
            return false;
        }

        $tenants = 0 === Artisan::call('ops:tenants-migrate', [], $output);

        // A tenant whose schema failed does not hold the others back: each command goes on past a failed tenant, and the
        // Solutions of the ones that did migrate are brought current too.
        $solutions = 0 === Artisan::call('solutions:migrate', [], $output);

        return $tenants && $solutions;
    }
}
