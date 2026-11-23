<?php

declare(strict_types=1);

use App\Domains\Flow\Retention\AutomationRunRetention;

return [
    // Only the defaults of the platform settings `flow.execution.max_iterations` (steps outside loops, and steps of one
    // loop iteration) and `flow.execution.max_loop_iterations` (iterations of one loop): a stored value wins
    // (`platform:settings:set`, or Platform settings in a single-tenant admin).
    'execution' => [
        'max_iterations'      => (int) env('FLOW_MAX_ITERATIONS', 100),
        'max_loop_iterations' => (int) env('FLOW_MAX_LOOP_ITERATIONS', 1000),
    ],

    // Only the default of the platform setting `flow.call.dedup_ttl_hours`: how long the `call` node remembers a
    // successful call, so a retried step is not sent again. Keep it above Horizon's failed-job
    // retention (`horizon.trim.failed`, 7 days); the setting's minimum is 168 hours. A retry from the `failed_jobs` table
    // past the window re-sends with the same key.
    'call' => [
        'dedup_ttl_hours' => (int) env('FLOW_CALL_DEDUP_TTL_HOURS', 192),
    ],

    // Only the defaults of the platform settings `flow.automation.segment_steps` and `flow.automation.segment_seconds`:
    // an automation run stops at its saved position after this many steps or seconds, whichever comes first, and
    // continues in a new job on `flow.automations`. The seconds stay under the job timeout (60 s) minus the slowest
    // node, which is why the setting caps them at 30.
    //
    // Only the default of the platform setting `flow.automation.max_run_hours`: how long an automation run may live.
    // The deadline is frozen when a run starts; every minute `flow:sweep-overdue-automation-runs` fails a run still
    // live past it, which cannot be undone. Held within 1 to 720.
    'automation' => [
        'segment_steps'   => (int) env('FLOW_AUTOMATION_SEGMENT_STEPS', 50),
        'segment_seconds' => (int) env('FLOW_AUTOMATION_SEGMENT_SECONDS', 20),
        'max_run_hours'   => (int) env('FLOW_AUTOMATION_MAX_RUN_HOURS', 24),
        // Only the default of the platform setting `flow.automations.runs_per_trigger_per_minute`: how many runs one
        // trigger other than a message may start in a minute before the run rate breaker pauses it.
        'runs_per_trigger_per_minute' => (int) env('FLOW_AUTOMATION_RUNS_PER_TRIGGER_PER_MINUTE', 60),
    ],

    // Only the defaults of the platform settings `flow.webhook.*`: how many requests one webhook trigger URL accepts in
    // a minute before it answers 429, and for a trigger in `sync` mode how long a request waits for the flow's answer
    // (at most 30 s) and how many requests may wait at once, on the installation and per tenant.
    'webhook' => [
        'per_token_per_minute'    => (int) env('FLOW_WEBHOOK_PER_TOKEN_PER_MINUTE', 120),
        'sync_wait_seconds'       => (int) env('FLOW_WEBHOOK_SYNC_WAIT_SECONDS', 30),
        'sync_waiters_max'        => (int) env('FLOW_WEBHOOK_SYNC_WAITERS_MAX', 5),
        'sync_waiters_per_tenant' => (int) env('FLOW_WEBHOOK_SYNC_WAITERS_PER_TENANT', 2),
    ],

    // Only the defaults of the platform settings `flow.automation_runs.succeeded_retention_days` and
    // `flow.automation_runs.failed_retention_days`: how many days a finished automation run is kept before the daily
    // `flow:prune-automation-runs` deletes it with its history and logs. 0 keeps that class forever; a value that is
    // not a whole number from 0 to 3650 is ignored. Conversation sessions are never pruned by it.
    'automation_runs' => [
        'succeeded_retention_days' => AutomationRunRetention::parseDays(
            env('FLOW_AUTOMATION_SUCCEEDED_RETENTION_DAYS'),
            AutomationRunRetention::DEFAULT_SUCCEEDED_DAYS,
        ),
        'failed_retention_days' => AutomationRunRetention::parseDays(
            env('FLOW_AUTOMATION_FAILED_RETENTION_DAYS'),
            AutomationRunRetention::DEFAULT_FAILED_DAYS,
        ),
    ],

    // Only the default of the platform setting `flow.trace.payloads`: whether an automation run's node trace keeps each
    // node's masked input and output. Conversation traces never keep them.
    'trace' => [
        'payloads' => (bool) env('FLOW_TRACE_PAYLOADS', true),
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
