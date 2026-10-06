<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Conversation\Retention\PartitionRetention;
use App\Domains\Conversation\Store\Postgres\ConversationPartitionManager;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Drops every tenant's expired conversation_messages monthly partitions.
 *
 * Retention is per install (`conversation.retention_days`); null keeps the
 * transcript forever and the command does nothing. Only whole partitions of the
 * current tenant schema are dropped — never rows, threads or media. Dropping is
 * irreversible.
 */
final class PruneConversationMessagesCommand extends Command
{
    protected $signature = 'conversations:prune {--dry-run : Preview dropped partitions only}';

    protected $description = 'Drops conversation_messages monthly partitions older than the retention period for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly DatabaseManager $database,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $retentionDays = config('conversation.retention_days');

        if (! is_int($retentionDays) || $retentionDays <= 0) {
            $raw = env('CONVERSATION_RETENTION_DAYS');

            if (null !== $raw && '' !== $raw) {
                $this->warn('CONVERSATION_RETENTION_DAYS is set but rejected: it must be a plain positive whole number of days.');
            }

            $this->info('Conversation retention is disabled (CONVERSATION_RETENTION_DAYS is not set); nothing to prune.');

            return self::SUCCESS;
        }

        $dryRun    = (bool)$this->option('dry-run');
        $now       = CarbonImmutable::now();
        $retention = new PartitionRetention($retentionDays);

        $dropped = 0;
        $failed  = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $tenantDropped = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): int => $this->pruneCurrentTenant($retention, $now, $dryRun),
                );

                $dropped += $tenantDropped;

                $this->line("✔ {$slug}: dropped {$tenantDropped}");
            } catch (Throwable $throwable) {
                $this->line("✘ {$slug}: {$throwable->getMessage()}");
                $failed++;
            }
        }

        logger()->info('conversation_messages retention prune finished', [
            'retention_days'     => $retentionDays,
            'dropped_partitions' => $dropped,
            'failed_tenants'     => $failed,
            'dry_run'            => $dryRun,
        ]);

        $this->info("Dropped partitions: {$dropped}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return int number of partitions dropped (or that would be dropped on a dry run)
     */
    private function pruneCurrentTenant(PartitionRetention $retention, CarbonImmutable $now, bool $dryRun): int
    {
        $partitions = new ConversationPartitionManager($this->database);

        $expired = $retention->expired($partitions->listMonthlyPartitions(), $now);

        if (! $dryRun) {
            foreach ($expired as $partitionName) {
                $partitions->dropPartition($partitionName);
            }
        }

        return count($expired);
    }
}
