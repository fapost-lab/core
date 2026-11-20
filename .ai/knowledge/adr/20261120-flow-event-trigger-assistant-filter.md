---
id: adr-20261120-flow-event-trigger-assistant-filter
type: adr
status: accepted
date: 2026-11-20
domains:
  - flow
paths:
  - app/Domains/Flow/Events/EventTriggerAssistantFilter.php
  - app/Domains/Flow/Services/ResolveEventTriggersService.php
  - app/Jobs/Flow/DispatchFlowTriggerEventJob.php
  - app/Domains/Flow/Validation/FlowTriggerConfigValidator.php
summary: Why an event trigger's assistant_filter matches the emitting session's assistant (read from flow_sessions, only when a trigger has a filter), is a list where empty means any, and is refused on Core events
reviewed_at: 2026-11-20
---
# An event trigger's `assistant_filter` matches the assistant of the session that emitted the event, read from the session row, and is refused on Core events

## Context

Spec `flow-automations`, phase 4, took over from `flow-engine-v1x` the old V1.x idea of an explicit assistant filter on
event triggers. Every event trigger of a tenant received a flow's `emit_event` whichever assistant's flow emitted it;
the only way to keep two bots' `order.created` apart was a naming convention. Since
`adr-20261119-core-events-start-contactless-runs` Core raises events too, and four of its five carry no assistant at
all, so "which assistant does a filter match" needed an answer. The event's source
(`{tenant_id, session_id, node_id, contact_id}`) and Foundation's `NodeExecutionContext` carry no assistant.
Approved at the design gate by the owner ("одобряю все", 2026-11-20).

## Decision

- **Shape.** `config.assistant_filter` on an event trigger: a list of assistant ids. Absent or empty means any assistant
  — the behaviour of every trigger saved before. `EventTriggerAssistantFilter` reads it.
- **What it matches.** The event's **source** assistant: the assistant of the session that ran `emit_event`
  (`flow_sessions.assistant_id`; a subflow child carries its parent's). The trigger's own assistant keeps its meaning —
  on whose behalf the started flow runs — and is not compared.
- **Where it is read.** `ResolveEventTriggersService::forFlowEvent()`, called by `DispatchFlowTriggerEventJob` on the
  flow-event path, reads the session row by `source.session_id` once per event and only when a resolved trigger has a
  filter. An unknown source (session gone, no assistant, no id) passes only triggers without a filter. A filtered-out
  trigger logs `flow.event.trigger_filtered` at debug level.
- **Core events.** Validation refuses a filter on a trigger that listens to a `CoreEventCatalog` name
  (`assistant_filter_on_core_event`); the builder hides the field for Core events and drops it when one is picked. The
  Core fan-out ignores the key.
- **Validation.** `FlowTriggerConfigValidator` takes only a list of non-empty strings; `ValidateFlowService` refuses
  an id that is not an assistant of the flow's tenant (`unknown_assistant_filter`).

## Alternatives

- **A single id, `filter.source_assistant_id` (the V1 sketch).** Cannot say "two of three bots". Rejected.
- **An implicit filter: a trigger of an assistant-bound flow catches only its own assistant's events.** Silently
  changes every live trigger. Rejected.
- **The publisher puts `CurrentAssistant` into `source.assistant_id`.** Depends on the scoped binding being set on every
  engine path, and jobs queued before the release arrive without it, so the session row would be read anyway. Kept as
  the fallback if the read becomes a cost.
- **`assistantId` on Foundation's `NodeExecutionContext`.** A Foundation release ahead of Core for one node. Rejected.
- **Match a Core event's payload `assistant_id`.** Works for `core.operator_request.timed_out` only; on the other four a
  filtered trigger would silently never fire. Rejected for now; it can be added on top by lifting the refusal for
  catalog events that carry the field. **Ignore the filter on Core events**: a field that looks working and is not.
  Rejected.
- **The filter as a jsonb condition in `getActiveEventTriggers`.** Needs the source assistant before the query and an
  "empty or contains" condition, for a handful of triggers per event. Rejected.

## Consequences

- A trigger without a filter behaves exactly as before; a revert leaves the key in saved configs, ignored.
- One primary-key read of `flow_sessions` per flow event that has a filtered subscriber.
- An assistant deleted after publish stays in the filter and never matches; the next publish refuses it, and the
  builder shows it as a deleted assistant.
- A wrong filter shows as "the flow did not start": no run in the history, a debug log line.
