---
id: adr-20261118-segmented-automation-runs
type: adr
status: proposed
date: 2026-11-18
domains:
  - flow
paths:
  - app/Domains/Flow/Orchestration/AutomationRunner.php
  - app/Domains/Flow/Services/ExecutionSegment.php
  - app/Jobs/Flow/StartAutomationRunJob.php
  - app/Jobs/Flow/ContinueAutomationRunJob.php
  - app/Domains/Flow/Concurrency/LockScope.php
  - "database/migrations/tenant/*segmented_execution*"
  - app/Domains/Flow/Services/StepBudget.php
  - "database/migrations/tenant/*loop_step_count*"
summary: Why an automation run starts once per (trigger, fire key), runs in engine-level execution segments continued by a single valid segment_id, counts its steps per session, and is serialized on a (tenant, trigger) lock on its own queue flow.automations
reviewed_at: 2026-11-19
---
# An automation run starts once per fire key, runs in engine-level segments that a single valid continuation carries on, and is serialized on its trigger's lock

## Context

Spec `flow-automations` ("Execution and duplicates") makes Flow run automations started by triggers. Until this task an
engine call was one in-process loop inside one queue job, bounded only by `flow.execution.max_iterations` per call. An
automation with several `call` nodes outlives a job's timeout; a job reserved again while it still runs, or retried
after it started a run, repeats `call` side effects in somebody else's system, silently. Calls nest: a subflow child
runs inside its parent's step and a parent resumes inside its child's last step (depth up to 3). A contactless
session had no lock (`adr-20261118-contactless-runs-in-flow-sessions`). Task `flow-segmented-execution`, design
approved by the agent at the gate (unattended), 2026-11-18.

## Decision

- **Fire key.** `flow_sessions.fire_key` (what fired the trigger: a schedule slot, an event id, a webhook delivery id)
  is unique with `trigger_id`. `FlowRunOrigin::automation(trigger, assistant, fireKey)`; a fire key without a trigger
  is refused. A subflow child copies `trigger_id`, never `fire_key`.
- **Idempotent start.** `AutomationRunner::start()` runs under the trigger's lock: a run already there for the fire key
  is continued when `active` and left alone otherwise (parked, ended, failed); only a fire with no run starts one. A
  unique violation past the lock is read back as that run. The session row commits before its first node, so a
  killed start leaves a row its retry continues.
- **Segments in the engine.** For `run_kind = automation`, one engine call — nested calls included, sharing the scoped
  `ExecutionSegment` — stops before the next node once it ran at least one step and reached
  `flow.automation.segment_steps` (default 50) or `flow.automation.segment_seconds` (default 20, at most 30). The
  position is already saved by the last step. Only the outermost call hands the run on: it writes a new
  `segment_id` on the run's root session (the top of the subflow chain) and, after that commit, queues
  `ContinueAutomationRunJob` for the root through `AutomationContinuationSchedulerInterface`.
  Conversations are not segmented. Every entry point is bounded the same way — start, continuation, delay resume, the
  subflow sweeper.
- **One valid continuation.** A continuation runs only while the root still carries the `segment_id` it carries, and
  then runs whichever session of the run is `active` (the root, or down the `paused_subflow` chain the child running in
  its place — at most one is). A retry of the same job continues the run even when it was killed inside a nested call
  (a child running inside its parent's step, a parent resumed by its child's end); a job whose run was already handed
  on, or a duplicate, does nothing. A retried start finds the run by its fire key and continues its `active` session
  the same way, leaving the continuation it had queued stale.
- **A run moved on and left without a job is handed on.** A delay wake-up or a subflow-sweeper pass that moved an
  automation run on and then broke off (a lost lock) leaves it `active` with nothing queued, and neither retries it
  (the wake-up finds no parked marker, the sweeper only looks at `paused_subflow` parents). Both call
  `SegmentHandOff::handOnIfStranded()`: when the run has an `active` session, a new root `segment_id` and a
  continuation are written, as at the end of a segment.
- **Steps per run.** An automation session counts its steps in `flow_sessions.step_count`, in the transaction of each
  step; `flow.execution.max_iterations` applies to that count, so a loop with no exit cannot re-dispatch itself
  forever. Conversations keep the count per engine call.
- **Lock.** `LockScope::ofSession()` is the one place a session's lock is chosen: its contact's
  `(tenant, contact, assistant)` lock when it has a contact, `LockScope::forTrigger()` — `session_lock:{tenant}:trigger:{id}`
  — when it has none; neither is `ContactlessSessionException`. `FlowExecutionGuardInterface::runInScope()` takes it;
  `DelayedSessionResumer` and `SubflowTimeoutSweeper` use it, so contactless runs resume after a delay and are swept.
  An automation run's delay wake-up (`ResumeDelayedFlowSessionJob`) stays on `flow.execution` — the delay node cannot
  tell the scheduler what started the session — and runs at most one segment there; like the automation jobs it
  releases itself on a busy lock instead of spending an attempt.
  The sweeper skips (warning) a parent it cannot handle instead of ending its pass.
- **Queue.** `flow.automations`, served by the Horizon supervisor `automations` on the `redis` connection. Job timeout 60 s
  below `retry_after` 90 s, so a running segment is never reserved twice; 30 s budget + the slowest node (~18 s) + lock
  wait stays under 60 s. A busy lock releases the job (10 s) instead of spending an attempt (`retryUntil` one hour,
  `maxExceptions` 3). A stopped tenant drops a start and postpones a continuation.

## Alternatives

- Segmenting only in the continuation job, with the budget as a loop parameter — delay resume and the sweeper would run
  unbounded, and nested calls would spend the budget once per level.
- The segment id on the stopped session instead of the root — a job killed inside a nested call leaves a session no
  retry names (a child under a `paused_subflow` parent, a parent resumed by an ended child).
- The expected `version` in the continuation instead of `segment_id` — a job killed mid-segment, on retry, sees the
  version its own first attempt advanced and drops the run: `active` forever.
- Only `status = active` in the continuation — a retried job that already handed on its run starts a second chain.
- The step count in the job payload — reset by delay resumes and the sweeper; a `system.*` key — the engine would have
  to bypass the writer whitelist.
- `redis-long` for the queue — its 30-minute `retry_after` would hold back the retry of a killed segment.
- `whereNotNull('contact_id')` in the sweeper — contactless parents would never be swept.

## Consequences

- A continuation lost between the `segment_id` commit and the queue push (a process killed in that instant), or a
  sweeper pass killed outright (not an exception) after it resumed a run, leaves the run `active` until the
  run-deadline sweeper of `flow-run-deadline-sweeper` picks it up (`adr-20261119-automation-run-deadline`: the run
  fails at its deadline, 24 h by default; it is not resumed earlier). So does a run whose job broke off after a subflow
  child's end committed but before its parent was resumed (the parent waits in `paused_subflow` over an ended child,
  which no retry follows); the subflow sweeper then expires the parent at its timeout.
- A delay wake-up now waits out a busy lock — a conversation's too — for up to an hour past its due time instead of
  failing after three tries.
- A step repeated by a killed segment is the one step it had not persisted; the handler rule "a side effect must be safe
  to repeat" and `call` idempotency from `flow-engine-v1x` cover it.
- Nothing fires a trigger yet: `StartAutomationRunJob` is dispatched only by tests until the schedule, event and webhook
  triggers land.
- `FlowExecutionGuardInterface` gained a method; a test double implements both.

## Amendment 2026-11-19: loop-aware step budget

"Steps per run" above holds for the steps outside loop bodies and gains a second count for the steps inside them (task
`loop-aware-iteration-budget`). `step_count` counts an automation session's steps outside loop bodies over all its
segments and is capped by `flow.execution.max_iterations`, as before, so a runaway with no loop node still stops at
that many steps however many segments it spans. `loop_step_count` (tenant migration
`2026_11_19_000001_add_loop_step_count_to_flow_sessions`) counts the steps inside loop bodies; the two together are capped
by the run's ceiling `min(10000, max_iterations × (max_loop_iterations + 1))` (`StepBudget::ceiling()`), so a runaway
inside a loop body cannot re-dispatch itself either. `flow.execution.max_loop_iterations` (at most 3000) caps each loop
through its persisted iterator, over the whole run. A segment that starts inside a loop body knows it from the iterator
in state (the first `loop` node whose iterator is set), so a long loop spread over many segments charges its body to
the loop budget, not to `max_iterations`. Rejected: checking `step_count` (all steps) against the ceiling only — no
migration, but a runaway with no loop would have run about 10,000 steps (some 200 segments, its side effects repeated
that often) instead of 100. Conversations count everything per outermost engine call (one budget per session, shared with the nested call that
resumes a parent after its subflow child ended, so a subflow body neither restarts the loop's count nor its 30 s
clock); a conversation counts a loop's iterations from the iterator the call found, so a loop that waits for the
contact keeps working.
