<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Solution\Services\SolutionCache;
use Illuminate\Console\Command;

final class ClearSolutionsCacheCommand extends Command
{
    /** @var string */
    protected $signature = 'solutions:clear';

    /** @var string */
    protected $description = 'Remove the cached catalog of installed Solutions';

    public function handle(SolutionCache $cache): int
    {
        if (!$cache->forget()) {
            $this->components->error('Cannot remove ' . $cache->path());

            return self::FAILURE;
        }

        $this->components->info('Solution catalog cache cleared.');

        return self::SUCCESS;
    }
}
