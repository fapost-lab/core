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
            'interval_seconds' => (int) env('FLOW_LOCK_HEARTBEAT_INTERVAL', 10),
            // TTL the lock is refreshed back to on each tick.
            'extend_to_seconds' => (int) env('FLOW_LOCK_HEARTBEAT_EXTEND_TO', 30),
        ],
    ],

    /*
     * Egress guard for the `call` node. A call never connects to a private, loopback,
     * link-local, reserved or cloud-metadata address, however the address is reached
     * (literal, DNS name, DNS rebinding, redirect). There is no switch to turn it off.
     *
     * allow: comma-separated CIDR ranges, addresses and host names the operator
     *   trusts, e.g. "10.20.0.0/16,crm.internal". Empty by default. Cloud metadata
     *   addresses (169.254.169.254 and friends) cannot be allowed. "0.0.0.0/0"
     *   would defeat the guard and is logged as a warning.
     * proxy: explicit egress proxy for calls. The HTTP_PROXY / HTTPS_PROXY
     *   environment variables never apply to calls. The proxy must refuse private
     *   ranges itself: the connection pin cannot be applied behind it.
     */
    'egress' => [
        'allow'         => env('FLOW_EGRESS_ALLOW', ''),
        'max_redirects' => 5,
        'proxy'         => env('FLOW_EGRESS_PROXY'),
    ],
];
