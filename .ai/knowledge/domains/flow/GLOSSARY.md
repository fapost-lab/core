---
id: glossary-flow
type: glossary
status: active
summary: Flow definition vs session, node handler, sourceHandle, state namespace, markers, routing decisions
domains:
  - flow
topics: []
load: domain
paths:
  - "app/Domains/Flow/**"
  - "app/Infrastructure/Flow/**"
  - "app/Jobs/Flow/**"
  - "app/Console/Commands/Flow/**"
  - config/flow.php
  - "tests/*/Domains/Flow/**"
  - tests/Architecture/FlowRuntimeIsolationTest.php
  - tests/Architecture/HandlerVersionContractTest.php
reviewed_at: 2026-11-25
---
# Flow glossary

## Flow definition

An immutable published version of a flow, one row per `(flow_id, version)`. Publishing inserts a
new row and deactivates the previous one; a partial unique index allows one active definition
per flow. Informal synonyms: published flow, version.

## Flow session

One execution of a flow definition, usually for a contact. It snapshots `flow_definition_id`, and holds
`current_node_id` (the only navigation pointer), the `state` jsonb, a status, and an
optimistic-lock `version`. `run_kind` says what started it — `conversation` or `automation` — and
`trigger_id` which trigger; an automation may have no contact and no assistant (a contactless run,
`adr-20261118-contactless-runs-in-flow-sessions`). Informal synonyms: run, conversation (avoid — that is a Conversation
term).

## Node

One entry of a definition's graph: `{id, type, version, config}`.

## Node handler

The class that executes one node type, implementing `NodeHandlerInterface` and registered under
`type@version`.

## sourceHandle

The name of the output port a handler returns. The engine maps it to an edge; the handler never
names the next node. The builder calls the same thing a *handle*.

## stateChanges

The flat, namespaced keys a handler returns. `FlowSessionPersister` checks them against
`SystemStateNamespacePolicy` and merges them into session state.

## State namespace

The prefix of a state key (`system`, `flow`, `rag`, `module`, `contact`, `call`). The canonical
list is the foundation enum `StateNamespace`.

## Idempotency marker

A `system.*` key recording that a node's side effect already happened, checked before repeating
it: `system.sent_messages`, `system.set_tag.<node>`, `system.staff_notified.*`, `system.contacts_notified.*`.
`system.auth.<node>` is a counter rather than a marker: the attempts and sends of an `auth_request`
challenge, and the number of the last challenge once the node has exited.

## One-time code

The digits an `auth_request@2` node sends a contact by SMS or email (through a
`OneTimeCodeSenderInterface`) and waits to be typed back. Only its keyed hash is stored
(`AuthCodeStore`); it never enters the session state, the trace or a log.

## Session lock

A Redis lock `session_lock:<tenant>:<contact>:<assistant>` with a heartbeat, taken through
`FlowExecutionGuard` so one contact's session runs on one worker at a time. A contactless run takes its
trigger's lock, `session_lock:<tenant>:trigger:<trigger>`; `LockScope::ofSession()` picks between them and refuses
a session with neither (`ContactlessSessionException`).

## Fire key

What fired a trigger — a schedule slot, an event id, a webhook delivery id (`wh:`), an API call's `Idempotency-Key`
scoped to its token (`api:<token id>:<key>`, or `api:<ULID>` without one) — kept on the session as `fire_key`,
unique with `trigger_id`, so a trigger starts one run per fire.

## Run admission

The one check a trigger's new run passes before it exists (`TriggerRunAdmission`): a paused trigger, the run rate
breaker, the plan's `flow_runs` limit, in that order. A refused run is not created; it is counted on the trigger with
its reason (`trigger_paused`, `rate_breaker`, `plan_limit`). Conversations never pass it.

## Run rate breaker

The per-trigger limit on new runs a minute (`flow.automations.runs_per_trigger_per_minute`); a trigger over it is
paused in `flow_trigger_states` until a person resumes it. Not the same as switching the trigger off: `is_active` stays
what was published.

## Re-run from the start

A new automation run of a failed root run's trigger, started by a person from the run's page with the trigger payload
the failed run kept (`flow_sessions.trigger_payload`), under the fire key `rerun:<source session id>:<n>`. It sends
every call and message of the flow again; the failed run is left as it was. Not a retry: a node is never re-run on its
own.

## Core event

An event Core itself raises for event triggers — `core.contact.created`, `core.contact.tag_added`,
`core.contact.tag_removed`, `core.broadcast.completed`, `core.operator_request.timed_out` (`CoreEventCatalog`). It
starts a contactless run on every active trigger that names it, once per event id. The `core.` prefix is reserved: a
flow's `emit_event` cannot use it. Not a flow event (one an `emit_event` node raises).

## Event chain depth

How deep in a chain "event → run → event" a session was started: `flow_sessions.event_chain_depth`, 0 when no event
started it. An event raised inside a session is one deeper; past 5 it starts nothing
(`adr-20261119-core-events-start-contactless-runs`). Informal synonym: chain depth.

## Execution segment

The part of an automation run one queue job executes: at most `flow.automation.segment_steps` steps or
`flow.automation.segment_seconds` seconds, after which the run continues in a new job carrying the `segment_id`
of the run's root session (`adr-20261118-segmented-automation-runs`). Informal synonym: segment.

## Node trace

The rows `node_entered`, `node_completed` and `node_failed` the engine writes to `flow_session_history` for every node
of every session, whatever `logging_enabled`; an automation run's rows also carry the node's masked `input` and
`output` (`adr-20261119-node-trace-in-session-history`). Not the flow log (`flow_logs`) and not the audit trail
(`state_change`, `subflow_*`). Informal synonym: run trace.

## Run deadline

The moment an automation run must be over, `deadline_at` on its sessions: its start plus
`flow.automation.max_run_hours`, frozen at the start and copied to its subflow children. A run still live past it is
failed whole by `flow:sweep-overdue-automation-runs`, with a **failure reason** (`failure_reason`:
`deadline_exceeded`, or `start_lost` for a run that never left `pending`); a session the engine failed itself has
none (`adr-20261119-automation-run-deadline`). Not the subflow timeout (`expires_at`).

## Stranded run

An automation run still live with no job to run it: its continuation never reached the queue, its job ran out of
attempts, or a parent waits in `paused_subflow` over a child that is already over. After
`flow.automation.recover_after_minutes` without movement, the recovery pass of `flow:sweep-overdue-automation-runs`
hands it on (`adr-20261125-stranded-automation-run-recovery`). Not a parked run: one in a `delay` or waiting for an
answer has its own wake-up.

## Routing decision

The outcome of `SessionStateRouter` for an inbound message: `StartViaTrigger`, `ResumeWaiting`,
`DropBusy`, `DropSilent`. A subflow parent (`paused_subflow`) is never selected: its waiting child is, and
the message resumes the child (`ResumeWaiting`).

## Trigger

What starts a flow: `message`, `schedule`, `webhook`, `api`, `event`. A flow may have several; each has a stable id,
the primary key of its live `flow_triggers` row, and goes live when the flow is published, not when the draft is saved.

## Automatic trigger

A trigger of any type but `message` (`schedule`, `webhook`, `api`, `event`): it starts runs without anyone writing to
the assistant. Making one live, changing or removing it, and turning on a flow that has one need `ManageAutomations`.
A flow switched off in the flows list starts no run from its automatic triggers.

## Subflow

A child session started by a subflow node. The parent waits in `paused_subflow`; the child
reports an `end_status` of `success`, `cancelled` or `failed`. The call graph is limited to
depth 3.

## Flow template

A flow draft with `kind = template`: a piece of a dialogue placed in flows as one `template` node and
copied into each of them when they are published; its named exits are `template_output` nodes. It
never runs on its own. Not the same as the `template` expression engine (below), and not a subflow,
which runs as a separate child session.

## Template copy

The nodes a published host carries for one `template` node, each with an `origin` naming the
reference node, the template and its version.

## Template parameter

A field of a node inside a flow template that the template exposes (node key `parameters`: key, label,
field). A flow that places the template may set its value on the reference node
(`config.parameter_values`); expansion puts it in the copy, and an empty value keeps the template's.

## Persistent button

An inline button that re-enters a finished session's branch when pressed.

## Expression engine

The evaluator used for templates in node config. The author chooses it per flow (`flow_drafts.expression_engine`)
and publishing snapshots its id onto the definition (`adr-20261119-expression-engine-chosen-per-flow`). Only the
`template` engine exists.

## Content translator

`ContentTranslatorInterface`: resolves a translation key to text in the content language —
assistant translations, then tenant translations, then the system catalog, then `en`, then the
key itself.
