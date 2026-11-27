<?php

declare(strict_types=1);

namespace App\Console\Platform;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the two commands the upgrade guide has always listed, then `solutions:migrate` once per Solution: each pass walks
 * the tenants for that Solution alone, so a failure is known by the Solution that caused it.
 */
final class ArtisanPlatformMigrator implements PlatformMigratorInterface
{
    public function migrateLandlord(OutputInterface $output): bool
    {
        return 0 === Artisan::call('migrate', ['--database' => 'landlord', '--force' => true], $output);
    }

    public function migrateTenants(OutputInterface $output): bool
    {
        return 0 === Artisan::call('ops:tenants-migrate', [], $output);
    }

    public function migrateSolutions(array $solutionIds, OutputInterface $output): array
    {
        $failed = [];

        foreach ($solutionIds as $solutionId) {
            if (0 !== Artisan::call('solutions:migrate', ['--solution' => $solutionId], $output)) {
                $failed[] = $solutionId;
            }
        }

        return $failed;
    }
}
