<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Domains\Tenancy\Services\WebhookRegistryHealthChecker;
use Illuminate\Console\Command;

/**
 * Operational consistency check between the Redis webhook routing cache and the
 * landlord webhook_registry table. Reports drift per failure mode; with
 * {@code --repair} restores Redis to match the DB (source of truth).
 */
final class WebhookRegistryHealthCommand extends Command
{
    /** @var string */
    protected $signature = 'ops:webhook-registry-health
        {--repair : Rewrite missing/stale entries from the DB and delete orphaned Redis keys}';

    /** @var string */
    protected $description = 'Check (and optionally repair) Redis↔DB consistency of the webhook routing registry';

    public function __construct(
        private readonly WebhookRegistryHealthChecker $checker,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->checker->check();

        $this->components->twoColumnDetail('Missing in Redis', (string)count($report->missing));
        $this->components->twoColumnDetail('Stale payload', (string)count($report->stale));
        $this->components->twoColumnDetail('Orphaned in Redis', (string)count($report->orphaned));

        if ($report->isHealthy()) {
            $this->components->info('Webhook registry is consistent.');

            return self::SUCCESS;
        }

        $this->listHashes('missing', $report->missing);
        $this->listHashes('stale', $report->stale);
        $this->listHashes('orphaned', $report->orphaned);

        if (! $this->option('repair')) {
            $this->components->warn('Drift detected. Re-run with --repair to restore Redis from the DB.');

            return self::FAILURE;
        }

        $this->checker->repair($report);
        $this->components->info('Repair applied: Redis rewritten from landlord webhook_registry.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $hashes
     */
    private function listHashes(string $kind, array $hashes): void
    {
        foreach ($hashes as $hash) {
            $this->line("  [{$kind}] {$hash}");
        }
    }
}
