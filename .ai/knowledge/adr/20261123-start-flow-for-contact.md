---
id: adr-20261123-start-flow-for-contact
type: adr
status: proposed
date: 2026-11-23
domains:
  - flow
paths:
  - app/Domains/Flow/Handlers/StartFlowNodeHandler.php
  - app/Jobs/Flow/StartFlowForContactJob.php
  - app/Domains/Flow/Services/ContactFlowStartCollision.php
  - app/Domains/Flow/Services/FlowSessionTerminator.php
summary: Why start_flow queues a once-only conversation (start_key) on the target flow's bot, is busy over a moving conversation or an operator, ends a stale one, and counts the event chain depth
reviewed_at: 2026-11-23
---
# A flow is started for a contact by a `start_flow` node that queues a once-only conversation on the target flow's bot, and never cuts into a moving conversation or an operator

## Context

Spec `flow-automations`, phase 5 ("Reaching people"): a run without a contact (a schedule, a webhook, an API call, a
Core event) must be able to start a conversation with a person, "the contact by expression; the same collision rules
as event starts today; no active channel is a `no_channel` outcome, not an exception". Discovery found that event
starts have no collision rule: `StartFlowFromEventJob` creates a second live session next to the contact's current one
(the old one keeps its buttons and lives for ever), ignores an operator who holds the thread, and a retried job starts
twice. Starting a conversation reaches a person who did not ask for it, a sent message cannot be taken back, and a
repeated step would write to them twice — so the class was T4 and every rule below was the owner's gate decision
(2026-11-23, "everything per the recommendations").

## Decision

- **A node, not a mode.** `start_flow@1` (category Logic, context `any`) names a target flow of the same assistant and
  a contact by the `send_message` addressee shape (`Support\AddresseeResolver`); it queues
  `StartFlowForContactJob` after commit and goes on by `started` without waiting. Exits: `started`, `busy`,
  `no_channel`, `error` (`addressee_unresolved`, `contact_not_found`, `target_inactive`, `chain_depth_exceeded`). It
  never falls back to the run's contact.
- **The target's bot.** The conversation belongs to the target flow's draft assistant, so a run without an assistant
  can start one; `no_channel` means the contact has no `channel_contacts` row on an active channel of that assistant
  (`ContactChannelProbe`, the pick `FlowMessageSender` makes).
- **Once per step.** `start_key = <engine execution key>:<node id>` is written on the started session; the column has
  a plain unique index (NULLs distinct on PostgreSQL and SQLite, which equals the partial index the design named, with
  no raw DDL). The node answers `started` when a session with its key exists; the job starts nothing for a used key,
  under the lock and again through the unique violation of a racing duplicate. A loop step gets a new key per pass.
- **Collision.** Busy — nothing sent — when the delivery thread is `staff` or `queued`, when a live (`inFlight`)
  conversation of the contact with that bot moved within `busy_window_minutes` (0–1440, default 30; 0 = never by
  time), or when that conversation is the asking session or a parent of it (a flow never ends itself, whatever the
  window), or when a live conversation sleeps in a `delay` (`paused`: it is due to move on its own — a follow-up, a
  reminder — so it is never ended by a start; review finding F1). A live conversation older than the window is ended as `/reset` ends one (`FlowSessionTerminator`,
  `terminated_by_user`, its pending operator request cancelled, waiting subflow parents ended) and replaced. The node
  checks for a visible `busy`; the job checks again under `FlowExecutionGuard::run(tenant, contact, target assistant)`
  and its answer holds — the run is not told.
- **Depth.** The started conversation's `event_chain_depth` is the run's + 1; past
  `FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH` the node leaves by `error/chain_depth_exceeded`, so flows that
  start each other stop as event chains do.
- **No new right**, as `send_message` with an addressee; the target's access policy is not applied, as for an event
  start. Event starts follow the same collision and once-only rules (amended 2026-11-23: `adr-20261123-event-start-collisions`; this line first said they were left as they were).

## Alternatives

- `subflow` without waiting, or `emit_event` plus an event trigger: both run in the asking session's world (its
  contact, its lock) or add a second hop that carries the contact as an untyped payload; neither gives a `no_channel`
  or `busy` exit the author can wire.
- Collision "literally as event starts today" (start beside the old session, over the operator), always replace, or
  always busy: the first cuts into an operator and leaves a ghost session, the second ends a conversation that is
  moving, the third never replaces a conversation abandoned days ago.
- Once-only by `fire_key` (needs a trigger, which a conversation has not), Redis `SET NX` (expires, not durable) or a
  `system.*` marker (written after the side effect, so a crash between repeats it).
- The run's assistant instead of the target's: a run without an assistant could never start a conversation.

## Consequences

- A conversation that has not moved within the window is ended by a start: a late press of one of its old buttons does
  nothing. The price of never having two live conversations with one bot.
- The node's `started` is a promise the job may not keep (the contact became busy or lost the channel); it is visible
  only in the log `flow.start_for_contact.*`.
- A conversation flow that names its own contact on its own bot always leaves by `busy`; to move the contact on, use a
  subflow or end the flow.
- `flow_sessions.start_key` stays after a revert (the down migration drops it); published `start_flow@1` nodes would no
  longer run and must be republished without the node.
