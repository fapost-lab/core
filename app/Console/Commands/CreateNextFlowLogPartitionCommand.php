<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class CreateNextFlowLogPartitionCommand extends Command
{
    protected $signature = 'logs:create-partition';

    protected $description = 'Creates next month flow_logs partition when missing';

    public function __construct(
        private readonly FlowLogPartitionManagerInterface $partitions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $nextMonth = CarbonImmutable::now()->addMonth()->startOfMonth();
        $name      = 'flow_logs_' . $nextMonth->format('Y_m');

        $this->partitions->ensureMonthlyPartition($nextMonth);

        $this->info("Ensured partition: {$name}");

        return self::SUCCESS;
    }
}
