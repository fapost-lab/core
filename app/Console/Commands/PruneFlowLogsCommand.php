<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Flow\Logging\FlowLogPartitionManager;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Drops every tenant's expired flow_logs partitions.
 *
 * `flow_logs` lives in the tenant schema, so partitions have to be listed and
 * dropped inside tenant context — running against the default connection alone
 * targets a schema that has no flow_logs at all, and the retention prune
 * silently does nothing while partitions pile up forever.
 */
final class PruneFlowLogsCommand extends Command
{
    protected $signature = 'logs:prune-flow {--dry-run : Preview dropped partitions only}';

    protected $description = 'Drops flow_logs monthly partitions older than 30 days for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly DatabaseManager $database,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $cutoff = CarbonImmutable::now()->subDays(30);

        $dropped = 0;
        $failed  = 0;

        foreach ($this->tenants->findAllActive() as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $tenantDropped = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): int => $this->pruneCurrentTenant($cutoff, $dryRun),
                );

                $dropped += $tenantDropped;

                $this->line("✔ {$slug}: dropped {$tenantDropped}");
            } catch (Throwable $throwable) {
                $this->line("✘ {$slug}: {$throwable->getMessage()}");
                $failed++;
            }
        }

        logger()->info('flow_logs retention prune finished', [
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
    private function pruneCurrentTenant(CarbonImmutable $cutoff, bool $dryRun): int
    {
        // Built inside the switch: switching tenants purges and rebuilds the
        // default connection, so a manager captured beforehand would still be
        // pointing at the old schema.
        $partitions = new FlowLogPartitionManager($this->database->connection());

        $dropped = 0;

        foreach ($partitions->listMonthlyPartitions() as $partitionName) {
            $month = $this->partitionMonth($partitionName);

            if (null === $month || $month->addMonth()->greaterThan($cutoff)) {
                continue;
            }

            if (! $dryRun) {
                $partitions->dropPartition($partitionName);
            }

            $dropped++;
        }

        return $dropped;
    }

    private function partitionMonth(string $partitionName): ?CarbonImmutable
    {
        if (! Str::startsWith($partitionName, 'flow_logs_')) {
            return null;
        }

        $suffix = Str::after($partitionName, 'flow_logs_');
        $month  = CarbonImmutable::createFromFormat('Y_m', $suffix, 'UTC');

        return false === $month ? null : $month->startOfMonth();
    }
}
