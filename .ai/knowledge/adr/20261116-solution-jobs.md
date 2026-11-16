---
id: adr-20261116-solution-jobs
type: adr
status: accepted
date: 2026-11-16
domains:
  - solution
  - tenancy
paths:
  - app/Infrastructure/Solution/CoreSolutionQueue.php
  - app/Infrastructure/Solution/RunsSolutionJob.php
  - app/Infrastructure/Solution/SolutionQueues.php
  - app/Providers/HorizonServiceProvider.php
  - config/horizon.php
summary: "A Solution queues jobs only through Core's SolutionQueueInterface (Foundation 0.39 SolutionJob with final handle/middleware): tenant stamp, ownership by package path, scalar-only payload, timeout ≤ 1500 s, queue solution-jobs.<id> on redis-long with a Horizon supervisor built from the catalog; RunsSolutionJob drops or postpones (no attempt spent) when the tenant is gone/stopped or the Solution inactive/not ready"
---
# A Solution queues jobs only through Core's dispatcher, on a queue of its own, and Core runs them in their tenant

## Context

A Solution cannot import `App\` and Foundation had neither a job type nor a way to know the tenant, so a Solution's
job would have run in no tenant's schema. Core's own jobs carry a `tenantId`, call `runForTenant()` themselves and
return the access-mode middleware; `RuntimeJobAccessModeTest` enforces that, but only for classes under `app/`.
Laravel restores serialized models before any job middleware runs, i.e. before a tenant switch. The AI Solution needs
background work (indexing). Decided at the gate of task `solution-queue`, 2026-11-16.

## Decision

- **Foundation 0.39 (experimental):** `Fapost\Foundation\Solution\Queue\SolutionJob` (abstract, `ShouldQueue`; the
  author implements `solution()` and `perform()`; `handle()`, `middleware()` and `uniqueId()` are final, so Core's gate
  cannot be bypassed; `uniqueId()` is unused while uniqueness is refused), `SolutionQueueInterface` (`dispatch()`,
  `MAX_TIMEOUT_SECONDS = 1500`), `SolutionJobMiddlewareInterface`, `SolutionJobRejectedException`. No new Foundation
  dependency: no `Queueable`.
- **Dispatch (`CoreSolutionQueue`)** is the only way. It refuses a job:
  - outside a tenant;
  - of a Solution not installed, or whose class file does not lie inside that Solution's package (ownership by `realpath`);
  - with a timeout below 1 s (it would turn the worker's alarm off) or above 1500 s;
  - with a property, private ones of intermediate parents included (`get_mangled_object_vars`), that is not null, a
    scalar, a backed enum or an array of those;
  - with Laravel's queue attributes on the class chain (they win over the stamped connection, queue and checked
    timeout), a `queue()` method (the dispatcher hands the push to it), a `middleware` property (run after the gate);
  - implementing `ShouldBeUnique`: Laravel takes the lock only in the `dispatch()` helper's `PendingDispatch`, not in the
    dispatcher, and `UntilProcessing` breaks on a gated job without `InteractsWithQueue`.

  It stamps the tenant id and routes the job to `solution-jobs.<id>` on `redis-long`.
- **Queues:** one per installed Solution, served by one Horizon supervisor `solution-jobs` (`balance: auto`); the list is
  filled at boot from the catalog (`SolutionQueues::configureHorizon()`, with `maxProcesses` = queues + 1, since Horizon
  keeps one process per queue), and the supervisor is removed when no Solution is installed, because Horizon would
  otherwise give it `default`. A new Solution's queue is served after `horizon:terminate`.
- **Run (`RunsSolutionJob`):** no tenant stamp → the job fails (queued around the dispatcher); tenant gone → dropped;
  tenant stopped → a copy one hour later, without a limit (like Core's runtime jobs); inside `runForTenant()`: Solution
  not active → dropped; tables not ready → preparation queued and a copy a minute later, counted in
  `SolutionJob::$postponements` and dropped with a warning after 48; otherwise it runs. A put-back copy spends no
  attempt. On the sync connection a postponement is a drop. Every drop and postponement is logged
  (`solution_job.dropped|postponed`).

## Alternatives

- A global `Queue::createPayloadUsing` hook stamping the tenant on any Solution job — implicit, and no place to check the
  payload or the owner.
- The tenant taken in the base class constructor — Foundation does not know Core's tenant context, and a job may be
  built outside a tenant.
- One shared `solutions` queue — one Solution could hold the others back. Core's queues — against `queues.md`.
- `release()` while the tables are not ready — spends attempts, so a long preparation failure exhausts the job.

## Consequences

- Authors cannot add their own job middleware (`middleware()` is final), make a job unique, or chain and batch jobs;
  each waits for a need (uniqueness needs Core to take Laravel's `UniqueLock` itself).
- Deviations from the gate's design, made during implementation: queues are `solution-jobs.<id>` (not `solutions.<id>`,
  which collides with Core's `solutions.migrate` for a Solution named `migrate`); "not ready" puts a copy back instead of
  `release()` (no attempt spent); the base class has no `Queueable` (no new Foundation dependency).
- A plain `dispatch()` is caught only when the job runs (it fails with the reason); the testing kit catches it earlier.
- A removed package leaves its queued jobs to fail on a missing class (`extension-installer`'s install order handles it).
