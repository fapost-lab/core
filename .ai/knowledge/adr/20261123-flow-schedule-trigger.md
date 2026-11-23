---
id: adr-20261123-flow-schedule-trigger
type: adr
status: proposed
date: 2026-11-23
domains:
  - flow
  - tenancy
paths:
  - "app/Domains/Flow/Schedule/**"
  - app/Domains/Tenancy/Contracts/FlowScheduleRegistryInterface.php
  - app/Domains/Tenancy/Infrastructure/LandlordFlowScheduleRegistry.php
  - app/Console/Commands/Flow/FireScheduledTriggersCommand.php
  - app/Console/Commands/Flow/RebuildScheduleRegistryCommand.php
  - "database/migrations/landlord/*flow_schedule_registry*"
summary: Why a schedule claims its slot by compare-and-set on the tenant's flow_triggers.next_run_at and creates its run pending in the same transaction, with a derived landlord registry naming the tenants to visit, rebuilt hourly; DST, no catch-up and skip-if-running rules
---
# A schedule trigger claims its slot in the tenant's `flow_triggers.next_run_at` and creates its run `pending` in the same transaction; a landlord registry only says which tenants to visit

## Context

Spec `flow-automations` (phase 2, "Schedule trigger") asked for one minute's pass that reads a flat landlord registry
`(tenant, trigger, next_run_at)` and enters only the schemas that have something due, and for the slot claim
(compare-and-set on `next_run_at`) and the `pending` run to be one transaction. Both cannot hold at once: `landlord` is
another connection and may be another database (`LANDLORD_DB_DATABASE`), so no transaction spans the registry and a
tenant's `flow_sessions`. The cron library (`dragonmantank/cron-expression`, already a dependency of Laravel) moves a
local time that does not exist forward and returns a repeated local time twice, while the spec wants the first skipped
and the second fired once. A `pending` run could not be started at all: `AutomationRunner` continued only `active`
sessions. Approved at the design gate by the owner ("Да, всё по рекомендациям", 2026-11-23: the recommended option of
every open question).

## Decision

- **The truth is the tenant's `flow_triggers.next_run_at`.** `ScheduleTriggerFirer` claims a due slot by a
  compare-and-set on the row (`next_run_at` still the value read, `is_active`), moving it to the next slot and writing
  `last_run_at`, and in the same transaction of the tenant connection either creates the run `pending`
  (`FlowEngineInterface::prepare()`, definition pinned at the claim, deadline written) or records why not. The start
  job (`StartAutomationRunJob`, fire key `schedule:<UTC minute>`) is queued after the commit with a jitter of
  `crc32(trigger id) % 60` seconds; `AutomationRunner` activates a `pending` run (`FlowEngineInterface::activate()`,
  optimistic lock, `FlowStarted` once) and runs it. A lost start job leaves a visible `pending` run that the deadline
  sweeper fails as `start_lost`.
- **The landlord `flow_schedule_registry` is a derived index**, reached only through Tenancy's
  `FlowScheduleRegistryInterface`. It is written after the tenant's commit: by a publish and a flow deletion for the
  triggers they touched, and by the minute's pass, which rewrites a visited tenant's rows from its triggers. An early or
  left-over row costs one useless visit; a missing or late row is put right by `flow:rebuild-schedule-registry`, run
  hourly. Tenant decommission forgets the tenant's rows.
- **`flow:fire-scheduled-triggers`, every minute, without overlapping,** asks the registry for the due tenants, skips a
  tenant that is gone (its rows are forgotten), inactive or stopped (nothing is claimed; its slots are missed once it is
  back), and claims each due slot in the tenant. A per-tenant failure is printed and logged and the pass continues.
- **A slot's fate.** Claimed more than 5 minutes late: no run, `flow.schedule.tick_missed`, the next slot counted from
  now (no catch-up, one line per downtime). The flow cannot run (no active runnable definition, draft or assistant gone):
  `flow.schedule.tick_skipped` reason `flow_not_live`. A previous run of the trigger still `pending` or in flight
  (`active`, `waiting_input`, `paused`, `paused_subflow`, its root): `tick_skipped` reason `previous_run_live`.
  Skips are logged only; a counter on the trigger belongs to the run rate breaker.
- **Slots.** A cron of five fields (or a five-field macro) in an IANA time zone, refused otherwise at publish
  (`invalid_schedule_cron`, `invalid_schedule_timezone`). `ScheduleClock` takes a library candidate only when its own
  local minute and hour match the expression (a nonexistent local time is skipped) and when that local time did not
  already happen at another offset (a repeated one fires once). The run's state carries `flow.schedule.{slot,
  local_time, timezone, cron}`.
- **When `next_run_at` is written.** Publish: an active schedule without a slot (new, cron or time zone changed, just
  switched on, or published before this release) gets the first slot after now; a kept one keeps its slot; a switched
  off one loses it. Flow deletion clears the slots of its schedules. Schedules published before this release have no
  slot and stay idle until their flow is published again; they are not backfilled. `flow_drafts.is_active` does not stop a
  schedule, as it stops no other trigger.

## Alternatives

- The registry as the truth, the claim in landlord and the `pending` run in a second tenant transaction — rejected: a
  crash between them eats a slot with no trace (the spec's failure 11).
- No registry, every schema visited every minute — rejected by the spec: the pass outgrows the minute, and without
  catch-up a late pass is a lost slot.
- The registry in Redis — rejected: lost on restart, and a rebuild from the tenants is needed anyway.
- No `pending` run, only moving `next_run_at` and queueing the job — rejected: a lost job is a slot eaten with no trace.
- Skip-if-running by the trigger's lock — rejected: the lock is held only while a segment executes, not while a run
  waits in a `delay` or for an answer, and claiming under it would wait up to a segment per busy trigger.
- A queued "claim this tenant's slots" job per tenant — kept as the way to scale when the pass outgrows the minute
  (`tick_missed` without a downtime is the signal); today it would only delay claims behind the queue.

## Consequences

- `flow_triggers` has a second writer: the firer writes the run state (`next_run_at`, `last_run_at`) and flow deletion
  clears `next_run_at`; the definition columns are still written only by the publish sync.
- A schedule may stay silent for up to an hour after a registry write failed; a tenant visited for nothing costs one
  query.
- A `pending` run blocks its trigger's next slots until it starts or the sweeper fails it (about three hours).
- The operator must run `schedule:run` every minute (already required) and the landlord migration.
