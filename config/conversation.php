<?php

declare(strict_types=1);

use App\Domains\Conversation\Retention\PartitionRetention;

return [
    /*
    |--------------------------------------------------------------------------
    | Storage driver
    |--------------------------------------------------------------------------
    | Which backend persists the transcript. `postgres` is the default (tenant
    | schema, monthly partitions). Future column-store drivers (e.g. clickhouse)
    | plug in behind ConversationStoreInterface without touching capture sites.
    */
    'driver' => env('CONVERSATION_STORE', 'postgres'),

    /*
    |--------------------------------------------------------------------------
    | Global switch
    |--------------------------------------------------------------------------
    | When false, ConversationLogger short-circuits and no persistence job is
    | dispatched — message processing is unaffected.
    */
    'enabled' => (bool) env('CONVERSATION_LOGGING', true),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | null = keep forever (default). A plain positive whole number of days (anything else disables pruning) lets the daily
    | `conversations:prune` command drop whole monthly partitions older than
    | that, so a message lives between N and about N + 31 days. Applies to the
    | whole installation (a per-tenant value is not supported yet). Dropping is
    | irreversible; threads and media are never touched.
    */
    'retention_days' => PartitionRetention::parseDays(env('CONVERSATION_RETENTION_DAYS')),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Low-priority queue isolating log volume from transactional traffic.
    */
    'queue' => 'messaging.logging',

    'media' => [
        // Fetch inbound media through the Media domain (disk/path/dedup reused).
        'fetch' => (bool) env('CONVERSATION_MEDIA_FETCH', true),
    ],
];
