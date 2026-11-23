---
id: adr-20261123-flow-run-admission
type: adr
status: accepted
date: 2026-11-23
domains:
  - flow
  - tenancy
paths:
  - "app/Domains/Flow/Admission/**"
  - app/Domains/Flow/Orchestration/AutomationRunner.php
  - app/Jobs/Flow/StartFlowFromEventJob.php
  - app/Jobs/Flow/NotifyTriggerPausedJob.php
  - "database/migrations/tenant/*flow_trigger_states*"
summary: Why every automatic trigger start passes one admission (pause, run rate breaker, plan limit flow_runs on the existing per-period meter) once per new fire, why the pause lives in flow_trigger_states outside flow_triggers, and why only a person resumes it
reviewed_at: 2026-11-23
---
# A trigger's new run passes one admission — pause, run rate breaker, plan limit — before it is created; the breaker pauses the trigger outside `flow_triggers`, and only a person resumes it

## Context

Spec `flow-automations` (phase 2, "Run rate breaker and quota seam") asked for a Foundation "may this run start"
contract and run counter for the SaaS shell, a Core breaker that pauses a trigger running more than X runs a minute,
and a refusal counter with the last reason on the trigger. Foundation already has that contract: the operator's
`UsageMeterInterface` decides one unit of a per-period limit, idempotently by unit key, and Core reaches it only
through `PeriodQuota` (fail-open, refusals announced to the admins). Automatic runs start on four paths — the
automation runner (webhook, Core event, API, a trigger type an extension registers), a flow's own event (`StartFlowFromEventJob`, which bypasses the runner)
and the schedule's slot claim (which creates the run `pending`, so the runner only activates it). `flow_triggers` is
written only by a publish's sync and the schedule's claim (`rule-flow`), and `is_active` is what was published.
Approved at the design gate by the owner ("Да, всё по рекомендациям", 2026-11-23: the recommended option of every
decision D1–D8); the schedule and event details below were settled by the agent during implementation.

## Decision

- **One admission, once per new fire, after deduplication and before the run exists.** `TriggerRunAdmission::admit()`
  is asked by `AutomationRunner::start()` (every `StartAutomationRunJob`: webhook, Core event, registered trigger type,
  re-run) only when `(trigger_id, fire_key)` has no run (a retry or continuation is
  never asked again), by `StartFlowFromEventJob` before its start (since `adr-20261123-event-start-collisions`: under the
  contact's lock, after its start key, channel and collision checks; through `admitOnce()`, which remembers an allowed
  start by tenant and unit key for an hour, so the job's retry after a failure that followed the admission is not
  counted by the breaker again), and by `ScheduleTriggerFirer` inside the slot claim
  before `prepare()`. A message trigger is never asked: conversations are not runs of a trigger, and inbound volume has
  its own limit.
- **Order: pause, breaker, plan.** A paused trigger refuses (`trigger_paused`). The breaker counts every run asking
  to start in the trigger's fixed one-minute window (`RateLimiter`, key by tenant and trigger); over the platform
  setting `flow.automations.runs_per_trigger_per_minute` (default 60, at least 1) it refuses (`rate_breaker`) and
  pauses the trigger. Then the plan spends one unit of `flow_runs` (`LimitKind::PerPeriod`, registered by Core) through
  `PeriodQuota` with `RefusedWork::FlowRun` (`plan_limit`). A run the breaker stops spends no unit; a run the plan
  refuses still counts in the breaker's minute, so a loop under a used-up limit is still paused.
- **The meter's unit key is `run:` + SHA-256 of `<trigger id>:<what fired it>`**: the fire key for the runner and the
  schedule, the event id for a flow event (a retried fan-out queues new jobs, so a job id would count twice). The fire
  key itself — another system's delivery id for a webhook — never reaches the operator.
- **Pause and refusals live in `flow_trigger_states`** (tenant schema, one row per trigger, own ULID key, unique
  `trigger_id`, foreign key to `flow_triggers` cascading on delete), written only through
  `FlowTriggerStateRepositoryInterface`. A pause is a conditional update, so only the call that paused it queues the
  notice; `flow_triggers` and its `is_active` are never touched, and a publish never lifts a pause.
- **A refusal starts nothing and is counted on the trigger.** `TriggerRefusals` counts in the cache and adds the count
  to the row at most every 10 seconds per trigger; the builder adds what is still in the cache to what it shows. Logged
  `flow.trigger.refused`.
- **Fail-open.** A breaker window that cannot be counted lets the run through and is reported, as an operator failure
  does in `PeriodQuota`.
- **Only a person resumes.** `POST /builder/flows/{flow}/triggers/{trigger}/resume`, policy `resumeTrigger` (edit the
  flow and `ManageAutomations`), lifts the pause, writes `resumed_at`, clears the breaker window and keeps the refusal
  counter (`TriggerPauseService`). No timer lifts it.
- **Who hears about it.** The pause queues `NotifyTriggerPausedJob` after the commit (`messaging.system`, tenant switch,
  guard set once the flow and the recipients were read, right before delivery): one in-app notification to every active
  staff user who could resume the trigger — `ManageAutomations` and the flow's `resumeTrigger` policy, so access to its
  assistant — linking the flow through the named builder route; a flow deleted since is announced to nobody. The console's flow list marks a flow with a paused trigger; the builder's trigger panel shows the pause, the
  refused count and the last reason.
- **A webhook to a paused trigger answers 503 with `Retry-After: 300`** before the delivery is claimed, so the sender's
  retry after a resume runs. A refusal by the breaker or the plan after the 202 is recorded on the trigger only; a
  `sync` request then waits out its timeout and gets 202. An API trigger asks the same repository before it queues.

## Alternatives

- A new Foundation `RunAdmissionInterface` that also sees the trigger type and flow — rejected for now: the existing
  per-period contract answers "may this run start" and counts runs, without a Foundation release; it can be added when
  the SaaS shell needs more than a count.
- Breaker columns on `flow_triggers` — rejected: the next publish would rewrite them and the rule that only the sync
  writes the row would need an exception. Redis alone — rejected: a pause must survive a Redis flush.
- Lifting the pause automatically after some minutes, or on a publish that changed the trigger — rejected: a loop
  comes back by itself, and a publish changing nothing about the loop would restart it.
- Waking a `sync` webhook request with the refusal — rejected: it ties admission to the webhook reply store.
- Plan before breaker — rejected: the plan would pay for runs the breaker throws away.

## Consequences

- Every automatic start path must ask admission once per new fire; a new path (the API trigger) asks the same service.
- `flow_runs` appears in the operator's limit registry; the SaaS shell decides its value per plan and its meter counts
  it. Conversations stay uncounted.
- A refused schedule slot is spent, not retried; a refused event start is not retried either. A re-run of a failed run
  takes the trigger's path (`StartAutomationRunJob`), so it passes admission and spends a unit like any new run.
- The refusal count on the row can lag the cache by up to one window, and what the cache still holds is lost if Redis is
  flushed before the next refusal.
