<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Store\Postgres;

use App\Domains\Shared\Infrastructure\PostgresMonthlyPartitions;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

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

    private const string PARENT_TABLE = 'conversation_messages';

    public function __construct(
        private DatabaseManager $database,
        private PostgresMonthlyPartitions $partitions = new PostgresMonthlyPartitions(),
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

        return $this->partitions->list($this->database->connection(), self::PARENT_TABLE);
    }

    /**
     * Drops one monthly partition of the CURRENT schema, schema-qualified, in a
     * transaction with `lock_timeout = 5s`: DROP TABLE needs an ACCESS EXCLUSIVE
     * lock, and a long-running reader must not make every insert queue behind it.
     * A lock timeout surfaces as an exception; the next daily run retries.
     */
    public function dropPartition(string $name): void
    {
        $this->partitions->assertPartitionName(self::PARENT_TABLE, $name);

        if (! $this->isPostgres()) {
            return;
        }

        $this->partitions->drop($this->database->connection(), self::PARENT_TABLE, $name);
    }

    private function isPostgres(): bool
    {
        return 'pgsql' === $this->database->connection()->getDriverName();
    }
}
