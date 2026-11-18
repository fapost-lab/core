<?php

declare(strict_types=1);

namespace App\Domains\Shared\Infrastructure;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Lists and drops the monthly range partitions (`<parent>_YYYY_MM`) of a
 * partitioned table on a Postgres connection. Shared by the partition managers
 * of Flow and Conversation, which only differ by the parent table.
 *
 * Both operations act on the CURRENT schema (`current_schema()`) only: every
 * tenant schema holds identically named partitions, so a listing or a drop must
 * never reach another schema. The connection is passed per call and never
 * captured, because tenant switching rebuilds it.
 */
final readonly class PostgresMonthlyPartitions
{
    private const string LOCK_TIMEOUT = '5s';

    /**
     * Partition names of `$parentTable` in the current schema, oldest first.
     *
     * @return list<string>
     */
    public function list(ConnectionInterface $connection, string $parentTable): array
    {
        $this->assertTableName($parentTable);

        $rows = $connection->select(
            <<<'SQL'
SELECT child.relname AS partition_name
FROM pg_inherits
INNER JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
INNER JOIN pg_class child ON pg_inherits.inhrelid = child.oid
INNER JOIN pg_namespace parent_ns ON parent.relnamespace = parent_ns.oid
INNER JOIN pg_namespace child_ns ON child.relnamespace = child_ns.oid
WHERE parent.relname = ?
  AND parent_ns.nspname = current_schema()
  AND child_ns.nspname = current_schema()
  AND child.relname ~ ?
ORDER BY child.relname
SQL,
            [$parentTable, '^' . $parentTable . '_[0-9]{4}_[0-9]{2}$'],
        );

        return array_values(array_map(
            static fn (object $row): string => (string)$row->partition_name,
            $rows,
        ));
    }

    /**
     * Rejects a name that is not a monthly partition of `$parentTable`.
     *
     * @throws InvalidArgumentException
     */
    public function assertPartitionName(string $parentTable, string $partitionName): void
    {
        $this->assertTableName($parentTable);

        if (1 !== preg_match('/^' . $parentTable . '_\d{4}_\d{2}$/', $partitionName)) {
            throw new InvalidArgumentException("Invalid partition name: {$partitionName}");
        }
    }

    /**
     * Drops one monthly partition of the current schema, schema-qualified, in a
     * transaction with `lock_timeout = 5s`: DROP TABLE needs an ACCESS EXCLUSIVE
     * lock, and a long-running reader must not make every insert queue behind it.
     * A lock timeout surfaces as an exception; the caller's next run retries.
     */
    public function drop(ConnectionInterface $connection, string $parentTable, string $partitionName): void
    {
        $this->assertPartitionName($parentTable, $partitionName);

        $schema = $connection->selectOne('SELECT current_schema() AS schema_name')->schema_name ?? null;

        if (! is_string($schema) || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $schema)) {
            throw new InvalidArgumentException('Invalid current schema name.');
        }

        $connection->transaction(static function () use ($connection, $schema, $partitionName): void {
            $connection->statement("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");
            $connection->statement("DROP TABLE IF EXISTS \"{$schema}\".\"{$partitionName}\"");
        });
    }

    private function assertTableName(string $table): void
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException("Invalid table name: {$table}");
        }
    }
}
