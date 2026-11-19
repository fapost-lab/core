---
id: adr-20261119-core-events-start-contactless-runs
type: adr
status: proposed
date: 2026-11-19
domains:
  - flow
paths:
  - "app/Domains/Flow/Events/**"
  - app/Domains/Flow/Listeners/PublishCoreEvents.php
  - app/Domains/Flow/Services/EventChainContext.php
  - app/Domains/Flow/Validation/TriggerContactContext.php
  - app/Jobs/Flow/DispatchFlowTriggerEventJob.php
  - "database/migrations/tenant/*event_chain_depth*"
summary: "Why Core events start contactless runs through event triggers: catalog in code under the reserved core. prefix, event id as fire key, synchronous listeners with an after-commit fan-out, and an event chain depth (limit 5) kept on flow_sessions"
reviewed_at: 2026-11-19
---
# Core events start contactless runs through event triggers: a code catalog under a reserved `core.` prefix, an event id as the fire key, and a chain depth kept on the session

## Context

Spec `flow-automations`, phase 4: Core events start contactless automation runs. Until now the only event was a
flow's own `emit_event`, which started a run for the emitting contact (`StartFlowFromEventJob`); no Core domain raised
anything a trigger could listen to, nothing deduplicated an event's runs (a retried fan-out started them again), and
nothing bounded a chain "event → run → event → …" — a flow on event X that emits X looped for good. Contactless runs
(`AutomationRunner`, fire keys, segments, deadline) were built and had no producer. Approved at the design gate by the
owner ("Да, всё по рекомендациям", 2026-11-19) together with the event list, contactless starts for contact events,
`emit_event` unchanged, the reserved prefix, the depth limit on the contact path and payloads without personal data.

## Decision

- **The catalog is code.** `Flow/Events/CoreEventCatalog` names five events — `core.contact.created`,
  `core.contact.tag_added`, `core.contact.tag_removed`, `core.broadcast.completed`, `core.operator_request.timed_out`
  — and their payload fields: identifiers and short fields only, never a contact's name or phone; every payload adds
  `event_id` and `occurred_at`. The names are not stored in `tenant_events`; `TenantEventRepository` lists them first
  for the picker and the `unknown_event_trigger_selection` check.
- **`core.` is reserved.** Validation refuses an `emit_event` with such a name (`reserved_event_name`); at runtime the
  handler drops it with `flow.event.reserved_name_dropped` and takes `success` (definitions published before the
  reservation); `EmittedEventCollector` and the registry never write it. So a flow cannot fake a Core event.
- **The domains raise plain events, Flow listens.** Contact (`ContactCreated`, `ContactTagAdded`, `ContactTagRemoved`,
  tenant id read from the contact row), Broadcasting (`BroadcastCompleted`, raised only by the conditional update that
  wins) and Conversation (`OperatorRequestStateChanged`, now carrying the assistant and contact) do not depend on Flow;
  `Flow/Listeners/PublishCoreEvents` is synchronous and reports a failure instead of throwing it into the caller.
- **One publisher.** `QueuedFlowTriggerEventPublisher` mints the event id, drops a Core event no active trigger listens
  to (one indexed read), takes the chain depth, and queues `DispatchFlowTriggerEventJob` `->afterCommit()`. For a
  Core event the job fans out one `StartAutomationRunJob` per active trigger on that name, whatever its assistant, with
  `fire_key = event_id`: a retried fan-out starts each run once. A flow's event keeps its path (a run for the emitting
  contact; none from a contactless run).
- **A Core event gives no contact.** `TriggerContactContext::givesContact(type, config)` answers per trigger: an event
  trigger on a catalog name gives none, so the contact check (`node_needs_contact`) treats it like a schedule.
- **Chain depth.** `flow_sessions.event_chain_depth` (0 = no event started the run) is written at start from
  `FlowRunOrigin::$chainDepth` and copied to subflow children; the scoped `EventChainContext` stack is pushed from the
  row in `FlowEngine::executeLoop()` and popped in `finally`, so every job (segment, delay resume, subflow resume) knows
  the depth of what it runs. An event raised inside a session of depth d has depth d + 1, outside any run 1; deeper than
  `FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH` (5) it starts nothing and logs `flow.event.chain_depth_exceeded`
  — on the contact path of `emit_event` too. A conversation keeps its start depth for its whole life.

## Alternatives

- **Seed the catalog into every tenant's `tenant_events`.** A data step across all schemas for a static list, and a
  migration per new event. Rejected.
- **A `source: core|flow` flag on the trigger instead of a prefix.** The same name from a flow and from Core would be
  indistinguishable in logs and the picker, and forging would still need a ban. Rejected.
- **Flip `FlowTriggerType::Event->givesContact()` to false, or add a `core_event` trigger type.** The first refuses
  every published `emit_event` flow with contact nodes on its next publish; the second adds a type the next roadmap item
  (registrable trigger types) generalises anyway. Rejected.
- **The domains call Flow's publisher, or Flow observes the models.** New Contact→Flow and Broadcasting→Flow
  dependencies; observers fire on every save and cannot tell a new tag from an existing one. Rejected.
- **`ShouldDispatchAfterCommit` listeners.** The depth is read from the scoped stack when the event is raised; a
  listener run after the engine left the run would see depth 0. The after-commit guarantee sits on the queued job
  instead. Rejected.
- **The event id minted in the fan-out job, or derived from the fact.** A retried job would mint a new id and start a
  second run; `add()` and `findOrCreate()` have no stable fact id, and `emit_event` in a loop legitimately repeats one
  fact. Rejected.
- **Depth only in the scoped binding, or in `system.*` state; the limit checked at run start.** A segment in a new job
  would start at 0; the engine would bypass the writer whitelist; the fan-out would already have queued N jobs.
  Rejected.
- **The limit in `config/flow.php`** (the design's first form). Every key there is the default of a runtime setting
  (`convention-runtime-settings`); this is a safety fuse nobody tunes, kept as a constant like
  `FlowEngineInterface::MAX_STEPS_PER_RUN`.

## Consequences

- A burst of one event (a contact import) queues on the trigger lock: runs of one trigger go one at a time; starts
  wait up to an hour, then the sweeper marks them `start_lost`. A rate breaker is a later phase-2 item.
- The limit bounds a chain's length, not its width: N listeners a level can still give N^5 runs.
- Each new contact or tag costs one synchronous indexed read in the process that raised it. On PostgreSQL a failure of
  that read inside the caller's open transaction aborts the transaction although the listener swallows the exception.
- A conversation started at depth 5 raises nothing for the rest of its life.
- Repeating the engine step that raised an event (a killed segment) mints a new event id — the same "safe to repeat"
  rule as any handler side effect.
- Rolling back: flows saved with a `core.*` trigger stop starting and fail the picker check; the column can be dropped.
  A flow that already emitted a `core.*` name gets `reserved_event_name` on its next publish.
- Contact-context rule of `adr-20261118-node-contact-context-checked-at-publish` now decides per trigger, not per type.
