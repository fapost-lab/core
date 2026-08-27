<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Tenant-scoped publisher of flow trigger events emitted by `emit_event` node.
 *
 * The publisher is fire-and-forget from the caller's perspective: any heavy
 * fanout (resolving subscribed event triggers, starting downstream flows)
 * MUST happen asynchronously so that the emitting flow can continue without
 * blocking on subscribers.
 */
interface FlowTriggerEventPublisherInterface
{
    /**
     * Publish a tenant-scoped event. Implementation MUST be non-blocking and
     * idempotent-safe (subscribers are responsible for their own dedup).
     *
     * @param  array<string, mixed>  $payload    Resolved (post-template) payload.
     * @param  array<string, mixed>  $source     Origin metadata: flow_id, session_id, node_id, assistant_id.
     */
    public function publish(
        string $tenantId,
        string $eventName,
        array $payload,
        array $source,
    ): void;
}
