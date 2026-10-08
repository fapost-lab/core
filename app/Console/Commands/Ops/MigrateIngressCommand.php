<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Channels\Services\IngressMigrator;
use Illuminate\Console\Command;

/**
 * Reports and migrates channels still registered against a previous ingress host.
 *
 * Reporting is the default: re-registration talks to the provider once per
 * channel, so moving traffic is an explicit act (--apply), never a side effect
 * of asking how many channels are behind.
 */
final class MigrateIngressCommand extends Command
{
    /** @var string */
    protected $signature = 'ops:ingress-migrate
        {--apply : Queue re-registration; without it the command only reports drift}
        {--platform= : Restrict to a single platform}
        {--limit=50 : Maximum channels to queue per platform in this run}';

    /** @var string */
    protected $description = 'Report or migrate channels whose webhook still points at a previous ingress host';

    public function __construct(
        private readonly IngressMigrator $migrator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $drift = $this->migrator->drift();

        if (null !== $this->option('platform')) {
            $platform = (string) $this->option('platform');
            $drift    = array_intersect_key($drift, [$platform => true]);
        }

        if ([] === $drift) {
            $this->components->info('All channels are registered against the configured ingress host.');

            return self::SUCCESS;
        }

        foreach ($drift as $platform => $count) {
            $this->components->twoColumnDetail($platform, "{$count} channel(s) behind");
        }

        if (! $this->option('apply')) {
            $this->components->warn('Reporting only. Re-run with --apply to queue re-registration.');

            return self::SUCCESS;
        }

        $limit  = max(1, (int) $this->option('limit'));
        $queued = 0;

        foreach (array_keys($drift) as $platform) {
            $queued += $this->migrator->migrate((string) $platform, $limit);
        }

        $this->components->info(sprintf(
            'Queued %d channel(s) for re-registration. Re-run to continue with the next batch.',
            $queued,
        ));

        return self::SUCCESS;
    }
}
