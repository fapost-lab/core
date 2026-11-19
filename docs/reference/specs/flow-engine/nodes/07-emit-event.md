# Node: `emit_event`

Asynchronously emits an event that starts other flows through triggers.

**Type:** `emit_event`
**Version:** 1
**Class:** `app/Domains/Flow/Handlers/EmitEventNodeHandler.php` (category `Logic`)
**Idempotent:** publishing only queues a job; the node has no dedup marker of its own, so a re-run of
the node publishes again

## Config

```json
{
  "event_type": "sales.order.created",
  "payload": {
    "order_id": "{{flow.order_id}}",
    "amount": "{{flow.amount}}"
  }
}
```

**Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `event_type` | string | yes | Event name. A literal, not an expression |
| `payload` | object | no | Map of keys to templates, rendered recursively before publishing |

## Output handles

- `success`

An empty `event_type` throws `InvalidNodeConfigException`.

## Behavior

1. An `event_type` in Core's reserved namespace (`core.`, `CoreEventCatalog`) is not published: the node logs
   `flow.event.reserved_name_dropped` and takes `success`. Validation refuses such a name (`reserved_event_name`);
   this covers definitions published before the reservation.
2. Render `payload` (every string leaf) through `TemplateRenderer`.
3. Publish through `FlowTriggerEventPublisherInterface::publish(tenantId, eventName, payload, source)`
   with `source = {tenant_id, session_id, node_id, contact_id}`.
4. Return `Executed` with the `success` handle and metadata `event_type`. The engine does **not** wait
   for the event to be handled.

### Publisher chain

```
FlowTriggerEventPublisherInterface
  -> QueuedFlowTriggerEventPublisher        (app/Domains/Flow/Events/)
       mints the event id, takes the chain depth (the running session's + 1, from EventChainContext),
       drops an event deeper than MAX_CHAIN_DEPTH (5) with `flow.event.chain_depth_exceeded`, and
       dispatches DispatchFlowTriggerEventJob on queue `scheduled.triggers` after commit
  -> DispatchFlowTriggerEventJob            (app/Jobs/Flow/)
       switches to the tenant, registers the event name in the tenant registry (best effort),
       resolves subscribed event triggers (ResolveEventTriggersService) and fans out one job per trigger
  -> StartFlowFromEventJob                  (app/Jobs/Flow/), queue `scheduled.triggers`
       starts the subscribed flow through FlowEngine::start under the contact's session lock,
       recording the event's chain depth on the session
```

The same publisher carries Core's own events (`CoreEventCatalog`, raised by `Listeners/PublishCoreEvents`):
those are queued only when an active trigger listens, get `event_id` and `occurred_at` in their payload, and fan
out to `StartAutomationRunJob` — a contactless run per trigger with the event id as its fire key.

Details of the fan-out:

- The started flow runs for the **emitting contact** (`source.contact_id`). An event with no contact is
  skipped (a flow start needs a contact).
- The event payload is exposed to the started flow as `flow.event.*`.
- Each trigger gets its own `StartFlowFromEventJob` so one failing subscriber does not block the others.
- The contact-facing access policy (the public / auth gate for inbound starts) is intentionally not
  applied: wiring the trigger is the authorisation.
- A stale trigger (inactive flow, deleted contact or assistant) is skipped with a log line.

## Event scope

Events are scoped to the **tenant**. Every trigger subscribed to `event_type` receives it regardless
of the source assistant. The source assistant is not part of the event source and is not a filter.

Recommended naming, not enforced:

```
sales.order.created
support.ticket.opened
hr.employee.onboarded
```

Not built: an `assistant_filter` on the trigger config.

## Validation

`ValidateFlowService::validateEmitEventConfig`:

- `emit_event_missing_type`: `event_type` must be non-empty,
- `emit_event_template_in_type`: no `{{` template expression,
- `emit_event_invalid_type`: dot-separated segments of letters, digits and underscore.

On publish, the events a flow emits are collected (`EmittedEventCollector`) and registered in the
tenant event registry, so event triggers on any flow can subscribe to them before the event ever fires.

---

## Related

- [08-subflow.md](08-subflow.md) - the synchronous alternative for calling another flow
