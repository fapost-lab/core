---
id: adr-20261118-contactless-runs-in-flow-sessions
type: adr
status: proposed
date: 2026-11-18
domains:
  - flow
paths:
  - app/Domains/Flow/Enums/FlowRunKind.php
  - app/Domains/Flow/DTOs/FlowRunOrigin.php
  - app/Domains/Flow/State/Exceptions/ContactlessSessionException.php
  - "database/migrations/tenant/*contactless_flow_sessions*"
summary: Why contactless automation runs are flow_sessions rows with nullable contact/assistant, a caller-stated run_kind and an FK-less trigger_id, and why the reader, writer and lock scope throw ContactlessSessionException
---
# A flow session may run without a contact or an assistant; what started it is `run_kind` and `trigger_id` on the same row

## Context

Spec `flow-automations` makes Flow run automations started by a schedule, an event, a webhook or the API, with no
conversational contact. Until then every `flow_sessions` row had a contact and an assistant (both `NOT NULL`,
`cascadeOnDelete`), and the engine, the state reader and writer, the language resolver and the session lock all
assumed one. Task `flow-contactless-sessions` laid the base; later tasks (`flow-contact-context`,
`flow-segmented-execution`, `flow-run-retention`) build on it.

## Decision

- Contactless runs are `flow_sessions` rows. `contact_id` is nullable and keeps `cascadeOnDelete`; `assistant_id` is
  nullable with `nullOnDelete`, so a run's history outlives its assistant — conversations included, which the cascade
  used to erase.
- `run_kind` (`conversation` | `automation`, default `conversation`) says what started the session; it is stated by
  the caller (`FlowRunOrigin`), not derived from the contact, because a later "start a flow for a contact" run is an
  automation with a contact and retention decides by kind. On PostgreSQL a CHECK keeps the values and another keeps a
  session without a contact an automation.
- `trigger_id` is the firing trigger, a uuid with no foreign key (like `flow_triggers.flow_id`): trigger ids become
  stable definition ids, and a run's history must not go with its trigger. Its index comes with the fire key.
- `FlowEngineInterface::start(definition, ?Contact, initialState, ?FlowRunOrigin)`: without a contact the tenant comes
  from the definition and the assistant only from the origin, never from the ambient current assistant. A subflow
  child copies the parent's `run_kind` and `trigger_id`.
- On a contactless session `ScopedStateReader` throws `ContactlessSessionException` for `contact.*` and `module.*`,
  `ContactWriter` for every write, and `LockScope` for an empty contact id — instead of a TypeError, a null, or one
  lock key shared by the whole tenant. This departs from the Foundation reader docblock ("never throws on missing
  paths"): such a path is not missing, it cannot exist for that kind of run.
- Foundation's `NodeExecutionContext` still types `contactId` and `platform` as strings; a contactless run passes
  `''` until `flow-contact-context` makes them nullable in a Foundation minor release.

## Alternatives

- A separate `flow_runs` table — rejected by the spec: parking, resume, subflows, logs and history would be duplicated.
- Deriving the kind from the presence of a contact — rejected: an automation can carry a contact.
- A foreign key from `trigger_id` to `flow_triggers` — rejected: wrong under either delete rule once ids are stable.
- Keying a contactless lock by session id — rejected: a subflow child would lock apart from its parent; the
  `(tenant, trigger)` lock belongs to `flow-segmented-execution`.

## Consequences

- Until `flow-segmented-execution`, a contactless run cannot take the session lock, so the rule "running the engine
  outside `FlowOrchestrator` requires the session lock" holds for it only because no production path starts one;
  deferred resumes of a contactless session (delay, timeout, subflow sweep) fail loudly.
- Handlers that use `NodeExecutionContext::$contactId` directly get `''` on a contactless run; publish-time context
  checks (`flow-contact-context`) are what keep contact nodes out of contactless flows.
- Conversation sessions of a deleted assistant stay with `assistant_id = null`.
