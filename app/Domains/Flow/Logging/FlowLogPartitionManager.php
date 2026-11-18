<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging;

use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use App\Domains\Shared\Infrastructure\PostgresMonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

final readonly class FlowLogPartitionManager implements FlowLogPartitionManagerInterface
{
    private const string PARENT_TABLE = 'flow_logs';

    public function __construct(
        private ConnectionInterface $connection,
        private PostgresMonthlyPartitions $partitions = new PostgresMonthlyPartitions(),
    ) {
    }

    public function ensureMonthlyPartition(CarbonImmutable $month): void
    {
        // Range partitioning is Postgres-only; other drivers (the sqlite test
        // connection) keep flow_logs as one plain table.
        if ('pgsql' !== $this->connection->getDriverName()) {
            return;
        }

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
     * Monthly partitions of flow_logs in the CURRENT schema only (the one
     * `current_schema()` reports). Without the schema filter a listing taken in
     * one tenant would also see the identically named partitions of every other
     * tenant schema.
     *
     * @return Collection<int, string>
     */
    public function listMonthlyPartitions(): Collection
    {
        return collect($this->partitions->list($this->connection, self::PARENT_TABLE));
    }

    /**
     * Drops one monthly partition of the CURRENT schema only (the one
     * `current_schema()` reports); it never reaches another tenant's schema.
     * Runs in a transaction with `lock_timeout = 5s`, so a long reader makes it
     * fail with an exception instead of queueing every log insert behind the
     * DROP; the next prune run retries.
     */
    public function dropPartition(string $partitionName): void
    {
        $this->partitions->drop($this->connection, self::PARENT_TABLE, $partitionName);
    }
}
