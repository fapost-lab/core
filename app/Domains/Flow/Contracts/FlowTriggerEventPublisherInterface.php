<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Tenant-scoped publisher of the events event triggers listen to: the ones a flow's `emit_event` node raises, and
 * Core's own ({@see \App\Domains\Flow\Events\CoreEventCatalog}), raised by Flow's listeners on the domain events of
 * Contact, Broadcasting and Conversation.
 *
 * The publisher is fire-and-forget from the caller's perspective: any heavy fanout (resolving subscribed event
 * triggers, starting downstream flows) happens asynchronously, queued only once the caller's transaction commits, so
 * the caller never blocks on subscribers and a rolled-back fact starts nothing.
 *
 * Every published event gets an id of its own, minted here before anything is queued: a Core event's runs use it as
 * their fire key, so a retried fan-out starts each run once. And every event has a chain depth: one more than the
 * session running when it is raised (0 outside any engine call), so a chain "event → run → event → …" stops after
 * {@see MAX_CHAIN_DEPTH} links: a deeper event starts nothing and is logged as `flow.event.chain_depth_exceeded`.
 */
interface FlowTriggerEventPublisherInterface
{
    /**
     * The deepest event that still starts runs. A safety fuse against flows that set each other off for good, not a
     * setting: a legitimate chain is a few links long.
     */
    public const int MAX_CHAIN_DEPTH = 5;

    /**
     * Publish a tenant-scoped event. A name from the Core catalog is a Core event: its payload gets `event_id` and
     * `occurred_at`, and it is queued only when some active trigger listens to it. Any other name is a flow's event.
     * A name in Core's reserved namespace that the catalog lacks is never published by a flow: the `emit_event` node
     * drops it before it gets here.
     *
     * @param  array<string, mixed>  $payload    Resolved (post-template) payload.
     * @param  array<string, mixed>  $source     Origin metadata: for `emit_event` tenant_id, session_id, node_id,
     *                                           contact_id; for a Core event `origin => core`.
     */
    public function publish(
        string $tenantId,
        string $eventName,
        array $payload,
        array $source,
    ): void;
}
