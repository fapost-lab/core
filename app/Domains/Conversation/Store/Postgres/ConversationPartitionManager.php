<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Store\Postgres;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensures monthly range partitions of conversation_messages exist before inserts
 * land in them (mirror of FlowLogPartitionManager). No-op on non-Postgres drivers
 * (e.g. the sqlite test connection uses a single non-partitioned table).
 */
final class ConversationPartitionManager
{
    private const string PREFIX = 'conversation_messages_';

    public function ensureMonthlyPartition(CarbonImmutable $month): void
    {
        if ('pgsql' !== Schema::getConnection()->getDriverName()) {
            return;
        }

        $start = $month->startOfMonth();
        $name  = $this->partitionName($start);

        $from = $start->format('Y-m-d H:i:sP');
        $to   = $start->addMonth()->format('Y-m-d H:i:sP');

        DB::statement(
            "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF conversation_messages FOR VALUES FROM ('{$from}') TO ('{$to}')"
        );
    }

    public function partitionName(CarbonImmutable $month): string
    {
        return self::PREFIX . $month->startOfMonth()->format('Y_m');
    }
}
