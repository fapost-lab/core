---
id: adr-20261119-automation-run-deadline
type: adr
status: proposed
date: 2026-11-19
domains:
  - flow
paths:
  - app/Domains/Flow/Orchestration/AutomationRunDeadline.php
  - app/Domains/Flow/Orchestration/AutomationRunDeadlineSweeper.php
  - app/Console/Commands/Flow/SweepOverdueAutomationRunsCommand.php
  - app/Domains/Flow/Enums/FlowRunFailureReason.php
  - "database/migrations/tenant/*run_deadline*"
summary: Why an automation run carries a deadline frozen at its start (flow.automation.max_run_hours, default 24), why the every-minute sweeper fails the whole live chain without running a node or the parent's failed handle, and the 3 h lost-start threshold for pending runs
reviewed_at: 2026-11-19
---
# Every automation run has a deadline frozen at its start; past it a sweeper fails the whole run, and runs no node

## Context

Spec `flow-automations`, phase 1: a contactless automation run killed mid-way must never keep its trigger and its flow
for good. `adr-20261118-segmented-automation-runs` left two windows open on purpose (its Consequences): a continuation
lost between the `segment_id` commit and the queue push, and a parent left in `paused_subflow` over a child that already
ended (finding F13). A run in either stays `active` (or `paused_subflow`) with nothing queued, and
`FlowDraftService::delete()` refuses its flow while it is live. A `pending` run whose start job is lost (the future
schedule trigger writes such rows) would wait forever too. Task `flow-run-deadline-sweeper`, T4; the owner approved
the design at the gate on 2026-11-19 (24 h deadline, 3 h lost-start threshold, early recovery deferred).

## Decision

- **A deadline per run, frozen at its start.** `FlowEngine::start()` writes `flow_sessions.deadline_at` = now + the
  platform setting `flow.automation.max_run_hours` (default 24, held within 1..720 in code, as the segment budget is)
  for `run_kind = automation` only; `SubflowStarterService` copies the parent's onto a child. A change of the setting
  never moves the deadline of a run already going. A row without one (written before the column existed) is measured
  from `created_at` with today's setting — no automation run is exempt, and there is no backfill.
- **A lost start.** A `pending` run older than `AutomationRunDeadline::PENDING_LOST_AFTER_SECONDS` =
  `AutomationRunner::START_RETRY_SECONDS` (the busy-lock retry window `StartAutomationRunJob::retryUntil()` uses) +
  `RespectsTenantAccessMode::POSTPONE_SECONDS` + one hour (3 h) has lost its start job.
- **The sweep.** `AutomationRunDeadlineSweeper`, run every minute by `flow:sweep-overdue-automation-runs` over every
  active tenant that is not stopped, takes the tenant id explicitly and filters every query by it. For each run with an
  overdue live session (deadline passed, or `pending` past the threshold) it takes the root's
  `LockScope::ofSession()` (the trigger lock its segments hold), re-reads the run under it, and fails **every live
  session of the chain** bottom-up in one transaction: `status = failed`, `failure_reason` = `start_lost` when the root
  is `pending`, else `deadline_exceeded`; the root's `segment_id` is cleared, so a queued continuation is stale.
  `current_node_id` is kept (where the run stopped stays visible). No node runs and no parent is routed through its
  subflow `failed` handle. After commit: `FlowFailed` analytics per session (payload adds `reason`) and one warning
  `flow.automation.run_overdue_failed` per run. A busy lock skips the run for the next pass; a version conflict rolls
  the whole run back for the next pass; any other error on one run is a warning and the pass goes on.
- **Conversations are never touched**: they get no deadline, and the sweep selects `run_kind = automation` only.
- **`failure_reason`** (`FlowRunFailureReason`) is set only for a failure from outside the engine; an engine failure
  (node error, step limit) keeps it `null`.
- **This amends ADR-11 (subflow composition) for automation runs:** a child failed by the run deadline does not hand
  its parent the `failed` output — the deadline bounds the whole run, so in an automation run it also caps a subflow
  node's `timeout` and any `delay` or input wait longer than the deadline.

## Alternatives

- Computing the deadline at sweep time from `created_at` + the setting — lowering the setting would fail, irreversibly
  and in the same minute, every run older than the new value.
- Reusing `expires_at` — it is the subflow timeout, which the subflow sweeper reads and `SubflowStarterService`
  extends; two meanings in one column.
- The reason in `flow_logs` (needs a node; pruned before the run) or `flow_session_history` (written only with logging
  on; a Foundation contract change) — rejected for a column on the session.
- Failing the child and resuming the parent through its `failed` handle, as the subflow sweeper does — runs nodes after
  the deadline, and the run can stick again.
- `expired` instead of `failed` — the spec says failed; the reason already tells the two apart.
- Selecting `contact_id IS NULL` — an automation with a contact would have no deadline.
- Early recovery of a stranded `active` run (hand it on after N minutes without movement) — out of this task's scope;
  deferred by the owner to a later task. Until then a run that lost its push waits for its deadline and fails.
- A deadline of 1–2 h (cuts legitimate long runs; failing is irreversible) or 7 days (a stuck every-minute trigger is
  silent for a week); a lost-start threshold equal to the deadline (holds the trigger 24 h) or 15 minutes (fails starts
  that legitimately wait on a lock or a stopped tenant).
- Folding the pass into `flow:sweep-subflow-timeouts`, running it every 5 minutes, or sweeping stopped tenants.

## Consequences

- A run killed mid-way frees its flow for deletion at most a deadline (24 h by default) after its start; a lost start,
  after 3 h. The windows of `adr-20261118-segmented-automation-runs` are closed by failure, not by recovery.
- Failing is irreversible: resume and re-run are not built. A legitimate automation that sleeps in `delay`, waits on a
  subflow or waits for input past the deadline is failed with no error branch in the flow. Publishing does not yet warn
  about a `delay` or timeout longer than the deadline.
- "Frees the trigger" means today that the flow can be deleted; skip-if-running is not built yet.
- The future schedule trigger (first producer of `pending` rows) must write `deadline_at` on its `pending` row, and a
  start must be able to launch a `pending` root (`liveSessionOf()` does not see `pending`). A tenant stopped for longer
  than one postponement re-postpones its start job every hour, so its `pending` runs can be failed as `start_lost` on
  the first pass after it is active again; that contract must settle it.
- A stopped tenant is skipped; on reactivation the first pass may fail at once everything that came due meanwhile.
- One more every-minute pass over every tenant schema; the PostgreSQL partial index over live automation sessions keeps
  it empty and cheap for most tenants.
- The lock is the trigger's because automation runs are contactless today; an automation run started with a contact
  would take a contact lock in the sweep but the trigger lock in `AutomationRunner`, so keep them contactless or align
  the scopes.
- `deadline_at` is a plain `timestamp`, like `expires_at` and `created_at` it is compared beside (the app runs in UTC).
- A contactless automation row without a `trigger_id` has no lock scope: it is skipped with a warning on every pass and
  never failed. No production path writes one.
