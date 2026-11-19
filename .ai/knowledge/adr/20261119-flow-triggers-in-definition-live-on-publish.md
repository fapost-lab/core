---
id: adr-20261119-flow-triggers-in-definition-live-on-publish
type: adr
status: accepted
date: 2026-11-19
domains:
  - flow
  - flow-builder
paths:
  - app/Domains/Flow/Services/FlowTriggerSet.php
  - app/Domains/Flow/Repositories/FlowTriggerRepository.php
  - app/Domains/Flow/Services/PublishFlowService.php
  - app/Domains/Flow/Services/SaveDraftService.php
  - app/Domains/Flow/Validation/ContactContextChecker.php
  - "database/migrations/tenant/*triggers*"
summary: Why a flow's triggers are a list in its draft and definition whose ids are the flow_triggers primary keys (null = live rows, no backfill), synced by id with run state kept, and made live only on publish
reviewed_at: 2026-11-19
---
# A flow's triggers live in its draft and definition, keyed by their row's primary key, and go live only on publish

## Context

Until spec `flow-automations` phase 2 a flow had one trigger, stored only in `flow_triggers` (`UNIQUE(flow_id)`), and
`SaveDraftService` wrote that row on every autosave: a trigger edit went live before any publish, without validation,
and before the contact-context check (`adr-20261118-node-contact-context-checked-at-publish`) could run. Phase 2 needs
several triggers per flow, per-trigger run state that survives a republish (a schedule's `next_run_at`), and spec
failure mode 9 forbids a release that drops the live trigger of an active flow. `flow_sessions.trigger_id` (#399)
already records the `flow_triggers` primary key of the trigger that started a run.

## Decision

- Each trigger is `{id, type, is_active, priority, config}` in `flow_drafts.triggers` and `flow_definitions.triggers`
  (jsonb). Its `id` **is the primary key of its live `flow_triggers` row**, a ULID in RFC 4122 form (see Consequences). `flow_triggers` is unique
  on `(flow_id, id)`; a flow may have several rows.
- `null` in `triggers` means "never written there": the flow's live rows are its triggers (`FlowTriggerSet::ofDraft()`,
  `ofDefinition()`). `[]` means the flow has none. Nothing is backfilled; a draft takes the live list on its first
  save from the builder, which loads it.
- A draft save stores the list and never touches `flow_triggers`. Publish validates every trigger (type, flag,
  priority, config, keywords against other flows' live rows, event in the registry, id: a UUID, once in the list,
  not another flow's row; no two event triggers on one event), snapshots the list onto the definition and syncs the
  rows by id (`FlowTriggerRepository::syncForFlow`): new id inserted with that id, missing id deleted, same id with the
  same type and config (compared ignoring jsonb key order) keeps `last_run_at`/`next_run_at` and is written only when
  its flag or priority changed, a changed type or config resets `next_run_at`.
- A republish caused by a template takes the list from the host's active definition, never from its draft; a `null`
  list there leaves the rows as they are.
- The contact check counts every trigger of the list, active or not: one contactless trigger makes the flow
  contactless.

## Alternatives

- **A separate `trigger_key` column, `UNIQUE(flow_id, trigger_key)`.** Keeps the primary key a server-made ULID
  made on insert, but every existing row needs its key backfilled in a step someone must run at release,
  and every resolver needs "key or primary key" until it has run; a publish before it could delete a live trigger.
  Rejected for that release risk.
- **Keep syncing on save, add more rows.** Leaves the unvalidated live edit and the contact-check gap. Rejected.
- **Backfill `triggers` from the rows in a data step.** Unnecessary with the `null` fallback, and a data step is not
  allowed in a migration (`convention-migration-isolation`).

## Consequences

- The key stays a ULID (ADR-03, `convention-id-strategy`), but a new trigger's key is minted where the trigger is
  made: by the builder (`resources/js/builder/utils/ulid.ts`, the same 48-bit time + 80 random bits in RFC 4122 form
  that `HasUlidPrimaryKey` writes), not by the model on insert. This is the one named exception to "generated through
  `HasUlidPrimaryKey`". Existing rows keep their ULIDs, and the existing `flow_sessions.trigger_id` values stay valid.
  Because a client sends it, publish refuses an id that is not UUID-shaped (`invalid_trigger_id`), repeats in the
  list (`duplicate_trigger_id`) or belongs to another flow's row (`trigger_id_taken`); ids are lowercased, as
  Postgres returns a uuid. The server cannot tell a ULID from another 128-bit value, so the ULID form is the
  builder's to keep.
- Publishing now checks trigger config, which it never did: a flow whose live trigger names an unknown event no
  longer publishes, and a template publish refuses when a host's trigger fails (`template_breaks_flow`).
- `ResolvedTrigger::triggerId` and the resolvers are unchanged: the stable id and the row key are one value.
- Deleting a flow still leaves its `flow_triggers` rows, as before.
- Rolling the migration back fails, deleting nothing, once a flow has several rows.
