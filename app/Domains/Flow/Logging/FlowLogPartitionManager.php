<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging;

use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final readonly class FlowLogPartitionManager implements FlowLogPartitionManagerInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    public function ensureMonthlyPartition(CarbonImmutable $month): void
    {
        $start = $month->startOfMonth();
        $end   = $start->addMonth();
        $name  = $this->partitionName($start);

        $this->connection->statement(
            sprintf(
                "CREATE TABLE IF NOT EXISTS %s PARTITION OF flow_logs FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $start->format('Y-m-d H:i:sP'),
                $end->format('Y-m-d H:i:sP'),
            )
        );
    }

    public function partitionName(CarbonImmutable $month): string
    {
        return 'flow_logs_' . $month->format('Y_m');
    }

    /**
     * @return Collection<int, string>
     */
    public function listMonthlyPartitions(): Collection
    {
        $rows = $this->connection->select(
            <<<'SQL'
SELECT child.relname AS partition_name
FROM pg_inherits
INNER JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
INNER JOIN pg_class child ON pg_inherits.inhrelid = child.oid
WHERE parent.relname = 'flow_logs'
  AND child.relname ~ '^flow_logs_[0-9]{4}_[0-9]{2}$'
ORDER BY child.relname
SQL
        );

        return collect($rows)
            ->map(static fn (object $row): string => (string)$row->partition_name)
            ->values();
    }

    public function dropPartition(string $partitionName): void
    {
        if (1 !== preg_match('/^flow_logs_\d{4}_\d{2}$/', $partitionName)) {
            throw new InvalidArgumentException("Invalid partition name: {$partitionName}");
        }

        $this->connection->statement("DROP TABLE IF EXISTS {$partitionName}");
    }
}
