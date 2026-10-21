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
    | Transcript logging (default only)
    |--------------------------------------------------------------------------
    | The install default of each tenant's `conversation_logging` setting
    | (Tenant settings > Conversations). A tenant that stored a value follows
    | its own; when logging is off no message is persisted and message
    | processing is unaffected. Read per tenant by ConversationSettings.
    */
    'enabled' => (bool) env('CONVERSATION_LOGGING', true),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | The default of the platform setting `conversation.retention_days`
    | (Platform settings, or `platform:settings:set`); a stored value wins.
    | null = keep forever (default). A plain positive whole number of days
    | (anything else disables pruning) lets the daily `conversations:prune`
    | command drop whole monthly partitions older than that, so a message lives
    | between N and about N + 31 days. One value for the whole installation.
    | Dropping is irreversible; threads and media are never touched.
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
        // The install default of each tenant's `conversation_media_fetch` setting:
        // fetch inbound media through the Media domain (disk/path/dedup reused).
        'fetch' => (bool) env('CONVERSATION_MEDIA_FETCH', true),
    ],
];
