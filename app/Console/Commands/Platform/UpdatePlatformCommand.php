<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Console\Platform\PlatformMigratorInterface;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Domains\Solution\DTO\SolutionDiscovery;
use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use Illuminate\Console\Command;

/**
 * The one command of an upgrade: check the installed Solutions, migrate, remember the result.
 *
 * Validation comes first and decides everything: a manifest with a violation stops the command before
 * the database or the cache is touched. `--validate-only` stops after that check and needs no database,
 * so an extension's CI and a derived image's build can run it.
 */
final class UpdatePlatformCommand extends Command
{
    /** @var string */
    protected $signature = 'platform:update
        {--validate-only : Only check the installed Solutions; touch neither the database nor the cache}';

    /** @var string */
    protected $description = 'Validate installed Solutions, migrate the landlord and tenant schemas, and cache the Solution catalog';

    public function handle(
        SolutionDiscoverer $discoverer,
        SolutionCache $cache,
        PlatformMigratorInterface $migrator,
    ): int {
        $discovery = $discoverer->discover();

        foreach ($discovery->warnings as $warning) {
            $this->components->warn($warning);
        }

        if (!$discovery->isValid()) {
            $this->reportViolations($discovery);

            return self::FAILURE;
        }

        $this->components->info(sprintf('Solution manifests are valid (%d installed).', count($discovery->valid)));
        $this->reportSolutions($discovery);

        if ((bool) $this->option('validate-only')) {
            return self::SUCCESS;
        }

        if (!$migrator->migrate($this->output)) {
            $this->components->error('Migrations failed. The Solution catalog was not cached; fix the failure and run the update again.');

            return self::FAILURE;
        }

        $cache->write($discovery);
        $this->components->info('Solution catalog cached.');
        $this->line('Restart the queue workers so they run the new code: php artisan horizon:terminate');

        return self::SUCCESS;
    }

    private function reportViolations(SolutionDiscovery $discovery): void
    {
        $this->components->error(sprintf('%d manifest problem(s). Nothing was changed.', count($discovery->violations)));

        foreach ($discovery->violations as $violation) {
            $this->line('  ' . $violation->line());
        }
    }

    private function reportSolutions(SolutionDiscovery $discovery): void
    {
        if ([] === $discovery->valid) {
            return;
        }

        $this->table(
            ['Id', 'Package', 'Version', 'Requires foundation'],
            array_map(
                static fn (InstalledSolution $solution): array => [
                    $solution->id,
                    $solution->package,
                    $solution->version ?? '-',
                    $solution->foundationConstraint,
                ],
                $discovery->valid,
            ),
        );
    }
}
