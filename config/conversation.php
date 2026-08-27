<?php

declare(strict_types=1);

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
    | null = keep forever (default). A number of days enables partition pruning
    | (per-tenant override via tenant settings is a future extension).
    */
    'retention_days' => null,

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
