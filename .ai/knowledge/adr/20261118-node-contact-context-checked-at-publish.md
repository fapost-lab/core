---
id: adr-20261118-node-contact-context-checked-at-publish
type: adr
status: proposed
date: 2026-11-18
domains:
  - flow
  - flow-builder
paths:
  - app/Domains/Flow/Validation/ContactContextChecker.php
  - app/Domains/Flow/Enums/NodeContextRequirement.php
  - app/Domains/Flow/Contracts/DeclaresContextRequirementInterface.php
summary: Why node handlers declare contact|none|any through a Core interface (default contact), and why validate/publish refuse a node that needs a contact in a flow whose trigger gives none, before Foundation carries the declaration
---
# A node handler declares whether it needs a contact, and publishing refuses a contact need in a flow whose trigger gives none

## Context

Spec `flow-automations` lets a schedule, a webhook or an API call start a flow without a contact
(`adr-20261118-contactless-runs-in-flow-sessions`). On such a run the state reader and the contact writer throw
`ContactlessSessionException`, and a handler that uses `NodeExecutionContext::$contactId` gets `''`. Those failures
happen at run time, in a run nobody watches. The spec asks that a flow which can start without a contact cannot be
published while anything in it needs one, and that every handler declares the context it needs, defaulting to
"needs a contact" so third-party handlers stay safe.

## Decision

- A handler declares what it needs by implementing `App\Domains\Flow\Contracts\DeclaresContextRequirementInterface`
  (`contextRequirement(): NodeContextRequirement` — `contact`, `none` or `any`). A handler that does not implement it
  needs a contact; every Solution node is in that position until Foundation carries the declaration.
- `none` means the node never uses a contact, `any` that it uses one only when there is one. Publishing treats them
  alike; nothing refuses a `none` node in a flow with a contact.
- Core declares `any` only for nodes checked not to reach the contact: `branch`, `delay`, `assign`, `end`, `loop`,
  `loop_end`, `template`, `template_output`. `call` keeps the default because it hands `NodeExecutionContext::$contactId`
  to its transport (`CallContext`, the legacy payload's `contact_id`) and so to unchecked Solution action handlers;
  it becomes `any` once Foundation's `CallContext` takes a nullable id. `subflow` keeps the default because its
  target would run contactless and is not followed; `emit_event` because event triggers still start for the event's contact;
  `notify` and `rag_query` because nobody checked them for a contactless run.
- A flow runs without a contact when its trigger's type gives none (`FlowTriggerType::givesContact()`: `schedule`,
  `webhook`, `api`). `/validate` uses the trigger in the request (`_delete` = none); publish uses the stored trigger,
  active or not, since switching a trigger on publishes nothing. No trigger means a contact.
- In such a flow `ContactContextChecker`, run by `ValidateFlowService`, refuses each node that needs a contact with
  `node_needs_contact` at `nodes.<id>`, naming the reason and the trigger: its handler needs one, or its config reads
  `contact` or `module` (placeholders found by the template engine's `extractReferences`, `{{contact}}` included;
  `source` operands; bare dotted paths — a lone word in a text field is not a read), uses a `storage: contact` variable, or is a legacy `assign` to the contact. `module.*` counts because a
  data accessor is asked for a contact. Templates are expanded before validation, so a template publish that would
  give a scheduled host a contact node is refused through the host's republish.
- Foundation is not changed by this decision: Core installs Foundation from its lock, and the check does not need a
  nullable `contactId`. Task `flow-node-context-foundation` moves the declaration into Foundation and makes
  `contactId`/`platform` nullable there (with `CallContext` and `ExpressionContext`).

## Alternatives

- A new handler version for every handler — rejected by the spec: the default declaration keeps old handlers safe.
- Declaring in Foundation now — rejected for this step: the Core pull request could not use an untagged Foundation
  release, and a Foundation surface Core does not use yet is unreviewed in practice.
- A run-time guard in `FlowEngine` that sends a contact node of a contactless session to `error` — not taken now:
  no trigger starts a contactless run yet, the reader, writer and lock already fail loudly, and the engine is being
  reworked by `flow-segmented-execution`.
- Following subflow targets (and callers, through `flow_callgraph_edges`) — deferred; `subflow` needs a contact
  instead, which refuses more than necessary and is easy to relax.

## Consequences

- A Solution node cannot be used in a flow with a schedule, webhook or API trigger until Foundation carries the
  declaration; the published handler contract says so.
- A flow saved with such a trigger and a contact node — which never ran, as these triggers start nothing yet — is
  refused at its next publish.
- A trigger changed on draft save goes live before the flow is published again, so until triggers go live on
  publish (spec `flow-automations`) the stored trigger and the active definition can disagree for a while.
- An automation cannot use `call` until `flow-node-context-foundation` makes `CallContext::$contactId` nullable and
  declares `call` `any`: the spec's first scenario (schedule, then `call`) waits for that task.
