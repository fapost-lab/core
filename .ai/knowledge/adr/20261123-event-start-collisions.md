---
id: adr-20261123-event-start-collisions
type: adr
status: accepted
date: 2026-11-23
domains:
  - flow
paths:
  - app/Jobs/Flow/StartFlowFromEventJob.php
  - app/Jobs/Flow/DispatchFlowTriggerEventJob.php
  - app/Domains/Flow/Services/ContactFlowStartCollision.php
summary: "Why a flow event's start keeps the start_flow rules: once per trigger and event (start_key), busy over an operator, a moving or sleeping conversation or the emitting one, no channel no start, admission after the collision"
reviewed_at: 2026-11-23
---
# A flow event starts its flow for the contact once per trigger and event, under the rules of a `start_flow` start: never over an operator, a moving or sleeping conversation or the emitting one, never without a channel

## Context

`StartFlowFromEventJob` (an `emit_event` event fanned out to a flow's event trigger) is the one automatic start that
runs for a contact. Discovery for `start_flow` (`adr-20261123-start-flow-for-contact`) found that it had no collision
rule: it created a second live session beside the contact's current one (the old one kept its buttons for ever),
wrote over an operator who held the thread, created a session for a contact with no channel on the trigger's bot, and
a retried job started twice (run admission deduplicated only the meter). The owner decided (D-6 of that task) to align
event starts with the `start_flow` rules in a separate task. A start reaches a person who did not ask for it, a sent
message cannot be taken back, and the new rule silently skips starts and ends "stale" conversations — so the class was
T4, and every rule below is the owner's gate decision (2026-11-23, "yes, everything per the recommendations").

Every other automatic start (webhook, Core events, schedules, registered trigger types, re-runs) is contactless and
has nothing to collide with; it is not touched.

## Decision

- **Once per trigger and event.** `start_key = "event:" . sha256(<trigger id> . ":" . <fire identity>)`, where the
  fire identity is `event:<event id>`, else (a job queued before event ids existed) `job:<queue job uuid>`. It is
  written on the started session (`flow_sessions.start_key`, unique). A used key starts nothing — before the lock,
  under it, and through the unique violation of a racing duplicate (`flow.event.start_duplicate`). The `event:` prefix
  never matches a `start_flow` key (`<execution key>:<node id>` or `sha256:…`).
- **Under the contact's lock, in order.** `FlowExecutionGuard::run(tenant, contact, the trigger's assistant)`: the key
  again; the delivery thread (`ContactChannelProbe`; none → `flow.event.start_skipped_no_channel`); the collision
  (`ContactFlowStartCollision::assess`, busy → `flow.event.start_skipped_busy` with `reason`); run admission; ending
  the stale conversations (`FlowSessionTerminator`, as `/reset`); `FlowEngine::start()` with the key. None of these
  outcomes is an exception.
- **The emitting conversation is the asking one** (gate question 1). `DispatchFlowTriggerEventJob` passes
  `source.session_id` as `sourceSessionId`; while that conversation (or a subflow parent of it) is live, the start is
  busy `own_conversation`. A flow that wants to hand the contact over ends after `emit_event`, or uses `subflow` or
  `start_flow`.
- **A live conversation of the contact with the trigger's bot** (question 2): busy when it moved within the window or
  sleeps in a `delay`; an older one is ended and replaced, as for `start_flow`.
- **A fixed window** (question 3): `ContactFlowStartCollision::DEFAULT_BUSY_WINDOW_MINUTES` (30), the same constant
  that is the default of a `start_flow` node's field. No trigger field, no tenant setting.
- **Admission after the collision** (question 4): `TriggerRunAdmission` is asked under the lock once the contact proved
  free and reachable, so a skipped start is neither metered against the plan's `flow_runs` nor counted by the run rate
  breaker. A refusal ends no conversation.
- **No channel, no start** (question 5): a contact without a `channel_contacts` row on an active channel of the
  trigger's assistant gets no session.

## Alternatives

- Start beside the emitting conversation, as before: two live sessions with one bot, the old one answering inbound
  messages last-updated-first. End the emitter and start (a hand-over): an `emit_event` with no `end` after it would
  cut its own conversation off. Wait for the emitter to end (a delayed retry with a limit): a retry loop with no
  natural end and a start the contact sees at a random moment.
- "Strictly as a message" (any live conversation → busy, nothing ended): an abandoned `waiting_input` would silence
  the contact's events for ever. Always replace, as `/start`: cuts a moving conversation.
- A `busy_window_minutes` field on the event trigger, or a tenant setting: builder, validation and docs work with no
  known need; a field can be added later without breaking anything.
- Admission before the lock, as before: a skipped start would spend the plan's quota and push the trigger towards an
  automatic pause.
- Start without a channel, as before: a session that can send nothing only fills logs and the quota.

## Consequences

- A published flow that emits an event and goes on talking to the contact on the same bot no longer starts the event's
  flow; the skip is visible only in the log `flow.event.start_skipped_busy` (`own_conversation`). The published docs
  (`reference/events`) say to end the emitting flow after `emit_event`.
- A conversation with the trigger's bot that has not moved for 30 minutes is ended by an event start; a late press of
  one of its old buttons does nothing.
- A revert brings back the start beside; it does not bring back conversations ended as stale or starts skipped as
  busy or without a channel (the event is not replayed). `start_key` stays on event sessions and harms nothing; no
  migration.
- Not covered here: `flow_drafts.is_active` is not read by the automation runtime (review finding F10 of
  `flow-automation-permissions`) — a separate problem.
