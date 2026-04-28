<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class PruneFlowLogsCommand extends Command
{
    protected $signature = 'logs:prune-flow {--dry-run : Preview dropped partitions only}';

    protected $description = 'Drops flow_logs monthly partitions older than 30 days';

    public function __construct(
        private readonly FlowLogPartitionManagerInterface $partitions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $cutoff = CarbonImmutable::now()->subDays(30);

        $dropped = 0;

        foreach ($this->partitions->listMonthlyPartitions() as $partitionName) {
            $month = $this->partitionMonth($partitionName);

            if (null === $month || $month->addMonth()->greaterThan($cutoff)) {
                continue;
            }

            if ( ! $dryRun) {
                $this->partitions->dropPartition($partitionName);
            }

            $dropped++;
        }

        logger()->info('flow_logs retention prune finished', [
            'dropped_partitions' => $dropped,
            'dry_run'            => $dryRun,
        ]);

        $this->info("Dropped partitions: {$dropped}");

        return self::SUCCESS;
    }

    private function partitionMonth(string $partitionName): ?CarbonImmutable
    {
        if ( ! Str::startsWith($partitionName, 'flow_logs_')) {
            return null;
        }

        $suffix = Str::after($partitionName, 'flow_logs_');
        $month  = CarbonImmutable::createFromFormat('Y_m', $suffix, 'UTC');

        return false === $month ? null : $month->startOfMonth();
    }
}
