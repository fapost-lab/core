<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Store\Postgres;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Creates, lists and drops the monthly range partitions of conversation_messages
 * (mirror of FlowLogPartitionManager). No-op on non-Postgres drivers (e.g. the
 * sqlite test connection uses a single non-partitioned table).
 *
 * The default connection is resolved on every call, never captured: tenant
 * switching purges and rebuilds it, and every tenant schema holds identically
 * named partitions.
 */
final readonly class ConversationPartitionManager
{
    private const string PREFIX = 'conversation_messages_';

    private const string NAME_PATTERN = '/^conversation_messages_\d{4}_\d{2}$/';

    public function __construct(
        private DatabaseManager $database,
    ) {
    }

    public function ensureMonthlyPartition(CarbonImmutable $month): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $start = $month->startOfMonth();
        $name  = $this->partitionName($start);

        $from = $start->format('Y-m-d H:i:sP');
        $to   = $start->addMonth()->format('Y-m-d H:i:sP');

        $this->database->connection()->statement(
            "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF conversation_messages FOR VALUES FROM ('{$from}') TO ('{$to}')"
        );
    }

    public function partitionName(CarbonImmutable $month): string
    {
        return self::PREFIX . $month->startOfMonth()->format('Y_m');
    }

    /**
     * Monthly partitions of conversation_messages in the CURRENT schema only.
     * Without the schema filter a listing taken in one tenant would also see
     * the identically named partitions of every other tenant schema.
     *
     * @return list<string>
     */
    public function listMonthlyPartitions(): array
    {
        if (! $this->isPostgres()) {
            return [];
        }

        $rows = $this->database->connection()->select(
            <<<'SQL'
SELECT child.relname AS partition_name
FROM pg_inherits
INNER JOIN pg_class parent ON pg_inherits.inhparent = parent.oid
INNER JOIN pg_class child ON pg_inherits.inhrelid = child.oid
INNER JOIN pg_namespace parent_ns ON parent.relnamespace = parent_ns.oid
INNER JOIN pg_namespace child_ns ON child.relnamespace = child_ns.oid
WHERE parent.relname = 'conversation_messages'
  AND parent_ns.nspname = current_schema()
  AND child_ns.nspname = current_schema()
  AND child.relname ~ '^conversation_messages_[0-9]{4}_[0-9]{2}$'
ORDER BY child.relname
SQL
        );

        return array_values(array_map(
            static fn (object $row): string => (string)$row->partition_name,
            $rows,
        ));
    }

    /**
     * Drops one monthly partition of the CURRENT schema, schema-qualified so a
     * search_path change cannot redirect it. Runs in a transaction with
     * `lock_timeout = 5s`: DROP TABLE needs an ACCESS EXCLUSIVE lock, and a
     * long-running reader must not make every insert queue behind it. A lock
     * timeout surfaces as an exception; the next daily run retries.
     */
    public function dropPartition(string $name): void
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException("Invalid partition name: {$name}");
        }

        if (! $this->isPostgres()) {
            return;
        }

        $connection = $this->database->connection();
        $row        = $connection->selectOne('SELECT current_schema() AS schema_name');
        $schema     = $row->schema_name ?? null;

        if (! is_string($schema)) {
            return;
        }

        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $schema)) {
            throw new InvalidArgumentException("Invalid schema name: {$schema}");
        }

        $connection->transaction(static function () use ($connection, $schema, $name): void {
            $connection->statement("SET LOCAL lock_timeout = '5s'");
            $connection->statement("DROP TABLE IF EXISTS \"{$schema}\".\"{$name}\"");
        });
    }

    private function isPostgres(): bool
    {
        return 'pgsql' === $this->database->connection()->getDriverName();
    }
}
