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
reviewed_at: 2026-11-11
---
# Flow glossary

## Flow definition

An immutable published version of a flow, one row per `(flow_id, version)`. Publishing inserts a
new row and deactivates the previous one; a partial unique index allows one active definition
per flow. Informal synonyms: published flow, version.

## Flow session

One execution of a flow definition for a contact. It snapshots `flow_definition_id`, and holds
`current_node_id` (the only navigation pointer), the `state` jsonb, a status, and an
optimistic-lock `version`. Informal synonyms: run, conversation (avoid — that is a Conversation
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

## Session lock

A Redis lock `session_lock:<tenant>:<contact>:<assistant>` with a heartbeat, taken through
`FlowExecutionGuard` so one contact's session runs on one worker at a time.

## Routing decision

The outcome of `SessionStateRouter` for an inbound message: `StartViaTrigger`, `ResumeWaiting`,
`DropBusy`, `DropSilent`. A subflow parent (`paused_subflow`) is never selected: its waiting child is, and
the message resumes the child (`ResumeWaiting`).

## Trigger

What starts a flow: `message`, `schedule`, `webhook`, `api`, `event`.

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

The evaluator used for templates in node config; its id is snapshotted per definition. Only the
`template` engine exists.

## Content translator

`ContentTranslatorInterface`: resolves a translation key to text in the content language —
assistant translations, then tenant translations, then the system catalog, then `en`, then the
key itself.
