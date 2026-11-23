---
id: adr-20261123-rerun-from-start-keeps-trigger-payload
type: adr
status: accepted
date: 2026-11-23
domains:
  - flow
paths:
  - app/Domains/Flow/Services/AutomationRunRerunService.php
  - app/Domains/Flow/Orchestration/RerunFireKey.php
  - "database/migrations/tenant/*trigger_payload*"
summary: Why a failed root automation run is re-run from the trigger payload kept in flow_sessions.trigger_payload, through StartAutomationRunJob under a deterministic rerun:<source>:<n> fire key, behind RerunFlow
reviewed_at: 2026-11-23
---
# A failed automation run is re-run from the start with the trigger payload kept on its row, through its trigger's start job under a deterministic `rerun:` fire key

## Context

Spec `flow-automations`, phase 9: "Re-run from the start — same trigger payload, `RerunFlow` permission". Re-running
from the failed node is set aside: it would need every node to be safely repeatable. The roadmap assumed the payload
would be in the node trace; it is not usable for that: the trace keeps payloads only while `flow.trace.payloads` is
on, masks secrets and tokens in URLs, and cuts a row at 1 MiB. The run's final `state` is no source either: the flow
may overwrite `flow.webhook.*` or `flow.event.*` through `input` and `assign`. A re-run repeats every outgoing `call`
(a new session is a new execution key, so a new `Idempotency-Key`) and every `send_message`: irreversible effects in
somebody else's system. Approved at the design gate by the owner ("Да, всё по рекомендациям", 2026-11-23).

## Decision

- **The payload is kept on the run's row.** `flow_sessions.trigger_payload` (nullable `jsonb`) is written by
  `FlowEngine::start()` with the `initialState` exactly as handed over, only when the start carries a fire key — a
  root automation run a trigger fired. Conversations and subflow children keep NULL; so do runs started before the
  column existed, which cannot be re-run. Not encrypted: the same data is already in `state`. It goes with the row when
  run retention deletes the run; no screen shows it.
- **Which runs.** Only a root (`parent_session_id IS NULL`) automation run whose status is `failed`, with a trigger, a
  fire key and a kept payload. A run that ended through an `end` node, even with a failed outcome, is not `failed`.
  Widening the set later is additive.
- **The path.** `AutomationRunRerunService::rerun()` queues `StartAutomationRunJob` for the source's tenant (passed
  explicitly), flow and trigger, with the kept payload, the source's assistant and its `event_chain_depth`. The
  trigger's lock, the `flow.automations` queue, the run's deadline and segments apply as to any start, and it runs the
  flow's latest active version. It is refused, with a reason and no exception, while the trigger row is not active, of
  another flow or tenant, or gone, the flow's draft is gone, or the flow has no active version. Since
  `adr-20261123-flow-run-admission` it is refused too while the run rate breaker has the trigger paused
  (`trigger_paused`): admission would refuse the queued run silently, after the person was told it was queued.
- **The fire key.** `rerun:<source session id>:<n>` (`RerunFireKey`). `n` is offered by the page — the highest attempt
  among the source's re-runs that still have a row, plus one (not their count: retention deletes each re-run on its
  own status, so a lower attempt can be gone while a higher one is kept) — and sent back with the request, which is refused (`already_rerun`) unless
  it is still the next one. Requests carrying the same `n` before the first job created its row make the same key, and
  the unique `(trigger_id, fire_key)` with `AutomationRunner::start()` turn them into one run; one that arrives after
  that run started (a second tab, a replay) starts nothing. The prefix meets no webhook key
  (`wh:`) and no event id; so a re-run answers no webhook sender and never touches the delivery dedup. The key is also
  the only link from a re-run to its source: the console lists a source's re-runs by the key prefix.
- **Who.** `FlowSessionPolicy::rerun()`: `ViewFlowSessions` and the new sensitive `RerunFlow` (automations group;
  `admin` through `Permission::cases()`, `content_manager` through `RoleEnum`, existing tenants by a tenant data
  migration). The console route is throttled (10 a minute) and finds the session within the assistant of the URL.
- **Audit.** Every queued re-run is recorded as `flow.run_rerun` (area `flows`) with the source session, the new
  run's fire key and the flow version it runs, committed before the job is queued.

## Alternatives

- **A copy under `system.trigger` in `state`.** No DDL, but `state` is rewritten on every step: up to 256 KiB more
  written per step of a webhook run, and the copy reaches expressions.
- **A table `flow_run_payloads`.** Keeps the hot table narrow, at the cost of a model, a repository and one more table
  retention and erasure must know. Open if the row size of `flow_sessions` becomes a problem.
- **Rebuild the payload from the trace or the final state.** Masked, cut or overwritten: the re-run would silently
  run on other data.
- **Start the re-run in the web request (`FlowEngine::start()` from the controller).** The first segment would run in
  the request, outside the trigger's lock and the automation queue.
- **A random key `rerun:<ulid>` with a separate double-click guard.** A second mechanism for what the unique index
  already does.
- **Every terminal status re-runnable, or the source's exact version.** Repeats side effects of runs that succeeded;
  running an unpublished version needs a start path past `AutomationRunner::start()`.

## Consequences

- A webhook run's row can grow by up to the body limit (256 KiB) for as long as the run is kept (7 days succeeded,
  30 failed).
- A deliberate second re-run asked while the first is still queued collapses into it; it can be asked again once the
  first has started and the page shows it.
- A new trigger kind gets re-runs for free when it starts runs through `StartAutomationRunJob` with a fire key.
- Runs without an assistant are not visible in the console and cannot be re-run from it (an existing gap of
  `FlowSessionInspector`).
- A re-run's `call` carries a new `Idempotency-Key`: a receiver deduplicating by it sees a new request, by design.
- The audit entry is committed before the job is queued, not in one transaction with it: the "change" is a queue push,
  which no database transaction holds. This narrows `adr-20261111-audit-log-explicit-recording` ("same transaction,
  fail-closed") for an action that only queues a job: no action goes unrecorded, but a refused push leaves an entry for
  a re-run that did not start (asking again makes the same key). Two requests with the same attempt before its run
  exists write two entries for one run.
- Listing a run's re-runs filters `fire_key LIKE 'rerun:<id>:%'` within its trigger; under a non-C collation the
  `(trigger_id, fire_key)` index narrows only by trigger, so the sessions page reads every kept run of the trigger.
  Bounded by run retention; an index with `text_pattern_ops` is the remedy if it shows.
