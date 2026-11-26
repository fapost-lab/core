---
id: adr-20261125-stranded-automation-run-recovery
type: adr
status: accepted
date: 2026-11-25
domains:
  - flow
paths:
  - app/Domains/Flow/Orchestration/StrandedAutomationRunRecovery.php
  - app/Domains/Flow/Services/SegmentHandOff.php
  - app/Domains/Flow/Orchestration/AutomationRunner.php
  - app/Console/Commands/Flow/SweepOverdueAutomationRunsCommand.php
---
# An automation run stranded without a job is handed on after minutes without movement, with the segment id it waits for

## Context

`adr-20261118-segmented-automation-runs` left windows open in which an automation run stays live with no job to run
it: a continuation lost between the `segment_id` commit and the queue push, a start or continuation that ran out of
attempts, and a job that broke off after a subflow child's last step committed but before its parent was resumed
(finding F13: the parent waits in `paused_subflow` over a child that is already over, and no retry follows it, because
`SegmentHandOff::liveSessionOf()` only walks `active` and `paused_subflow` children). `adr-20261119-automation-run-deadline`
closed those windows by failure at the run's deadline (24 h by default) and deferred early recovery. Task
`flow-run-early-recovery`, T3; the owner approved the recommended option of every gate question on 2026-11-23.

What looks the same as a stranded `active` run and must not be disturbed: a segment that is running (it holds the run's
lock), a continuation waiting in a backlog of `flow.automations`, and a continuation released on the trigger's busy lock
(up to `retryUntil`, about an hour plus a postponement; the lock is shared by every run of the trigger).

## Decision

- **F13 is repaired where segments run.** `SegmentHandOff::pendingReturnOf($root)` only reads: down the chain of
  `paused_subflow` sessions, the first parent whose **latest** child (any status, by `created_at` then ULID) is `ended`,
  `completed`, `failed` or `expired` (an orphan the subflow sweeper expired; amended 2026-11-25), while the parent still
  waits on the node that started it
  (`child.parent_resume_node_id = parent.current_node_id`). `AutomationRunner::continue()` and `continueFired()`, under
  the run's lock, run the `active` session; with none, they hand that child's end to the parent through
  `SubflowResumerInterface::resumeIfChild()` (the child's `end_status`, else success for `completed`/`ended`, failed for
  `failed`, as the engine maps it, and failed for `expired`, as the subflow sweeper hands an orphan back), and the parent runs on in that segment. So a retried start or continuation that used
  to be a no-op ends the run. `liveSessionOf()` stays a pure read: the delay and question resumers and the subflow
  sweeper call it, and parent nodes must never run on those paths. `handOnIfStranded()` also hands the run on when
  `pendingReturnOf()` names a child, so the continuation repairs it on `flow.automations`.
- **A recovery pass in `flow:sweep-overdue-automation-runs`.** `StrandedAutomationRunRecovery::recover($tenantId)`
  runs right after the deadline pass of the same tenant (stopped tenants are skipped by the same code; an overdue run is
  failed before recovery could see it). Candidates are live automation sessions in `active` or `paused_subflow` older
  than the threshold; each run once, by its root, and only when every session of the chain (a child that is already
  over included) last moved before the threshold and the run has something to hand on — an `active` session or a
  pending subflow return. That is checked without a lock first, so the trigger's lock is not taken for runs that are
  moving or parked, and again under `LockScope::ofSession(root)` with the deadline re-checked. A busy lock skips the run
  until the next pass; any other error is a warning and the pass goes on. Nothing here runs a node.
- **Handed on with the same segment id.** When the root carries a `segment_id`, the continuation of that same id is
  queued again after commit (`SegmentHandOff::redispatch()`); a continuation still waiting in the queue stays valid,
  and of the copies the first to run takes the run on, the others find the id moved on or nothing left to run. Only a
  root without a segment id (its start ran out of attempts before any segment ended) gets a new one through `handOn()`.
  The same `(root, segment)` is queued again at most once per threshold, marked with `Cache::add` (losing the mark costs
  one extra, harmless job). Log `flow.automation.run_recovered` with `root_id`, `trigger_id`, `segment_id`, `shape`
  (`active` | `subflow_return`) and `idle_seconds`; `flow.automation.subflow_return_recovered` when a segment hands a
  child's end back.
- **The threshold is a platform setting**, `flow.automation.recover_after_minutes`, default 10, held within 2..240 in
  code. Two minutes is the floor because a killed worker's job is back in the queue by then (`retry_after` counts from
  the job's reservation, which precedes the run's last write). Because recovery never stales a waiting continuation,
  the threshold need not exceed the backlog or `retryUntil`.

## Alternatives

- `handOnIfStranded()` as it was (a new `segment_id`) — stales a continuation that legitimately waits in a backlog or on
  the trigger's busy lock and sends the run to the back of the queue on every pass; safe only with a threshold above
  about 75 minutes, which is not early recovery.
- Repairing F13 inside `liveSessionOf()`, as the finding suggested — parent nodes (a `call` with its retry window
  included) would run in the delay wake-up, the question resumer and the subflow sweeper's cron pass, and a reader
  would start writing.
- Naming the F13 window only in this ADR's Consequences — the run still ends `expired` or `failed` instead of taking
  its exit.
- A separate every-minute command — a second walk over every tenant schema for the same predicate, and it could
  recover a run the deadline is about to fail.
- A constant instead of a setting — operators with a slow queue need a knob (`convention-runtime-settings`).
- A `recovered_at` column instead of the cache mark — a migration for throttling; touching the root's `updated_at`
  would fake movement.

## Consequences

- A run that lost its push, ran out of attempts or stranded over a finished subflow child continues within the
  threshold plus a minute; the deadline stays the last net for everything else.
- A run that only waits behind a long backlog costs one extra continuation per threshold, which turns out stale or a
  no-op; no step repeats beyond the ordinary "a step may repeat after a kill" (the lock, the segment id and the saved
  position serialize the copies).
- A deterministically failing job (a node that throws every time) is queued again once per threshold and fails its
  attempts again, until the deadline; visible in `flow.automation.run_recovered`.
- A parent whose child the subflow sweeper `expired` (an orphan whose hand-back to its own parent failed) is handed on
  like any other stranded parent and takes its `failed` exit within the threshold, instead of waiting for its own
  subflow timeout or the deadline (amended 2026-11-25, task `flow-run-recovery-fixes`).
- Every pass reads the idle live automation sessions of each tenant (the partial index of the deadline pass covers
  them); runs parked in a `delay` or on a question below a `paused_subflow` root are read but not locked.
- A recovery pass runs no node and writes no session row except the new `segment_id` of a root that had none.
