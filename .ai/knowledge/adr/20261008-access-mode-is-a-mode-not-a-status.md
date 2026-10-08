---
id: adr-20261008-access-mode-is-a-mode-not-a-status
type: adr
status: accepted
date: 2026-10-08
domains:
  - tenancy
paths:
  - "app/Domains/Tenancy/Queue/**"
  - app/Domains/Tenancy/Services/TenantRequestRunner.php
  - app/Domains/Tenancy/Services/CurrentAccessState.php
  - app/Http/Middleware/RefuseWritesWhenTenantStopped.php
---
# A tenant's access mode is a computed answer asked per request and per job, not a tenants.status value

## Context

An operator package (the closed SaaS shell) must be able to stop a tenant whose trial ended or whose
subscription lapsed: the assistant stops answering, scheduled work waits, the builder and media library take
no changes, and the data is kept (the Filament panel is deliberately not guarded, see below). Whether a tenant may run is the operator's computation (subscription state, paid
until, grace), not something Core stores.

Forces: `TenantStatus::Suspended` and `Inactive` already mean "the host answers 404", and every console
command (migrations, partition upkeep, cleanup) iterates `findAllActive()`, so a stopped tenant that left
`Active` would vanish from housekeeping. Channel providers retry any non-2xx answer, and the Go gateway
and the PHP webhook both answer 200 before any tenant work happens. Filament has no global read-only
mode and is frozen for new screens (it moves to the new UI kit). The Foundation ADR of the operator
side says the mode is computed, never switched by a job.

## Decision

- Foundation's `TenantAccessModeInterface::stateFor(string $tenantId): TenantAccessState` is implemented
  by the operator package. Core binds `AlwaysActiveAccessMode` with `bindIf`: an installation without an
  operator behaves exactly as before. `TenantAccessState` carries an `AccessMode` (`Active`, `Stopped`;
  callers keep a default arm) and an optional `AccessNotice` (title, message, action) for the banner.
- Stopped is a mode, not a status. The tenant stays `Active`; console commands, migrations, partitions
  and cleanup see it unchanged.
- **Request.** `TenantRequestRunner`, the one place both tenant middleware enter a tenant, asks once per
  request and stores the answer in the `scoped` `CurrentAccessState`. The write guard, the banner and
  the Inertia shared prop `accessState` read it from there; the runner clears it when the request ends.
- **Writes outside Filament.** `RefuseWritesWhenTenantStopped` is on the builder, media and mini-app
  routes: an unsafe method answers 423 Locked with the notice, reads carry on, a route with no side
  effects opts out (`/builder/flows/{flow}/validate`). The platform support user passes: in a stopped
  tenant an operator is there to look and, when need be, to fix. Logout, `/support/*` and activation are
  not under the middleware.
- **Filament** gets the banner only (`AccessNoticeBanner`, a render hook next to the support banner).
  No read-only mode is built there; until the new UI, a stopped tenant's administrator may still edit
  data in Filament, but the bot does not run.
- **Background work.** Job middleware `RespectsTenantAccessMode` asks the access mode of the job's tenant
  before the job runs; the job names its tenant through `TenantAccessGatedJob`. Stopped means:
  - *drop* with a log line, for work tied to a moment that has passed: the inbound message (before a
    contact is created), event triggers, notifications sent from a flow and their per-contact
    `BroadcastSendJob`s (no record to fan out from again);
  - *postpone*, for work that must survive the stop and is one job per session or per broadcast: delayed
    wake-ups, send-message timeouts and `RunBroadcastJob`. A copy is queued with a one-hour delay and the
    original ends; it is not `release()`d, because a release spends an attempt and a long stop would
    exhaust the tries. If the job's own connection is sync the delay would be ignored and the copy would
    loop, so there the job is dropped.
  - A broadcast has a job per recipient, so postponing those would put a delayed copy of each in the
    shared `messaging.broadcast` queue and trip backpressure for every other tenant. A
    `SendBroadcastRecipientJob` is therefore dropped (its recipient row stays Pending) and, through
    `DefersWhenDroppedWhileStopped`, queues one delayed `RunBroadcastJob` per broadcast (guarded by a cache
    flag). The re-run materializes idempotently and fans the Pending recipients out again once active.
  - The gateway's "Class@method" payload never goes through the queue's middleware pipeline, so
    `RawIncomingMessageHandler` runs the job's middleware itself (and deletes the queue job when it is
    gated out); `RuntimeJobAccessModeTest` fails for a gateway handler that does not.
  - **Fail open.** Core asks through `TenantAccessStates`: if `stateFor` throws, the exception is
    reported and the tenant counts as active. An operator outage must not stop every tenant.
  - Housekeeping keeps running: conversation record, delivery statuses, media fetch, webhook sync,
    activation mail, media cleanup.
- `flow:sweep-subflow-timeouts` skips stopped tenants, since the sweep force-fails expired chains and resumes
  their parents. Skipping only delays that: expiry is absolute, so the first sweep after renewal catches up.
- `RuntimeJobAccessModeTest` lists every queued job as runtime (gated) or keeping running and fails for a
  queued job in `app/` that is in neither list.

## Alternatives

- A `Stopped` value in `tenants.status`: the host answers 404 and every console command needs widening;
  and a job would flip the status, against the computed-mode rule.
- Refusing at ingress: needs the state in the Redis registry and in the Go gateway, and a non-2xx answer
  makes providers retry.
- A check inside `TenantSwitcher::runForTenant`: also hits console commands and housekeeping jobs.
- Read-only Filament now: new code in a frozen area; the new UI kit does it once, completely.
- Releasing postponed jobs: costs attempts.

## Consequences

- A stopped tenant keeps one delayed copy per waiting session wake-up, timeout and broadcast, re-queued
  hourly, until the tenant is active again or removed (retention deletes the tenant). Accepted. After
  renewal, postponed work resumes on that hourly grid, so up to an hour late.
- A recipient job queued before `broadcastId` was added to it names no broadcast, so one dropped while
  stopped is lost without a re-run (only jobs in flight at the deploy).
- Filament is not guarded: a stopped tenant's administrator can still edit data there until the new UI.
- Every new queued job forces a decision through `RuntimeJobAccessModeTest`.
- The operator's `stateFor` runs on every tenant request and every runtime job, so it must be fast and
  cached by the implementation, and should not throw.
- Messages that arrive while stopped are lost, not replayed: the conversation is not kept.
