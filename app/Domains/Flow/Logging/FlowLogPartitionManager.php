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
     * Monthly partitions of flow_logs in the CURRENT schema only. Without the
     * schema filter a listing taken in one tenant would also see the
     * identically named partitions of every other tenant schema.
     *
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
INNER JOIN pg_namespace parent_ns ON parent.relnamespace = parent_ns.oid
INNER JOIN pg_namespace child_ns ON child.relnamespace = child_ns.oid
WHERE parent.relname = 'flow_logs'
  AND parent_ns.nspname = current_schema()
  AND child_ns.nspname = current_schema()
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

        $schema = $this->connection->selectOne('SELECT current_schema() AS schema_name')->schema_name ?? null;

        if (! is_string($schema) || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $schema)) {
            throw new InvalidArgumentException('Invalid current schema name.');
        }

        // Schema-qualified so a search_path change cannot redirect the drop.
        $this->connection->statement("DROP TABLE IF EXISTS \"{$schema}\".\"{$partitionName}\"");
    }
}
