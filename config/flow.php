<?php

declare(strict_types=1);

return [
    'execution' => [
        'max_iterations' => (int) env('FLOW_MAX_ITERATIONS', 100),
    ],

    /*
     * Session execution lock — ADR Message Routing & Concurrency Control
     * § "Distributed Lock Strategy". One lock per (tenant, contact, assistant),
     * held for the whole run rather than per node.
     *
     * The TTL only has to cover the gap between two heartbeat ticks (the engine
     * extends before every node), not the full execution — so it is sized
     * against the slowest single node, not the slowest flow. Outbound calls cap
     * out below it: HttpTransport defaults to a 10 second timeout.
     */
    'lock' => [
        'ttl_seconds'         => (int) env('FLOW_LOCK_TTL_SECONDS', 30),
        'acquisition_retries' => (int) env('FLOW_LOCK_RETRIES', 3),
        'retry_delay_ms'      => (int) env('FLOW_LOCK_RETRY_DELAY_MS', 2000),

        'heartbeat' => [
            // Advisory cadence. The engine ticks per node, not on a timer.
            'interval_seconds'  => (int) env('FLOW_LOCK_HEARTBEAT_INTERVAL', 10),
            // TTL the lock is refreshed back to on each tick.
            'extend_to_seconds' => (int) env('FLOW_LOCK_HEARTBEAT_EXTEND_TO', 30),
        ],
    ],
];
