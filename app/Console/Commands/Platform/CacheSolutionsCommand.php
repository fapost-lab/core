<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Solution\Services\SolutionCache;
use App\Domains\Solution\Services\SolutionDiscoverer;
use Illuminate\Console\Command;

/**
 * Compiles the Solution catalog into bootstrap/cache. Runs on `optimize`; `platform:update` does the same after migrating.
 */
final class CacheSolutionsCommand extends Command
{
    /** @var string */
    protected $signature = 'solutions:cache';

    /** @var string */
    protected $description = 'Cache the catalog of installed Solutions';

    public function handle(SolutionDiscoverer $discoverer, SolutionCache $cache): int
    {
        $discovery = $discoverer->discover();

        if (!$discovery->isValid()) {
            $this->components->error('The Solution catalog was not cached: run php artisan platform:update --validate-only.');

            foreach ($discovery->violations as $violation) {
                $this->line('  ' . $violation->line());
            }

            return self::FAILURE;
        }

        $cache->write($discovery);
        $this->components->info(sprintf('Solution catalog cached (%d installed).', count($discovery->valid)));

        return self::SUCCESS;
    }
}
