---
id: convention-worker-safety
type: convention
status: active
domains: []
paths:
  - "app/Jobs/**"
  - "app/Providers/**"
  - "app/Domains/**"
  - "app/Infrastructure/**"
summary: Horizon workers are long-lived processes; no singleton may retain request/tenant/assistant state across jobs, tenants switch via TenantSwitcher::runForTenant().
reviewed_at: 2026-10-05
---
# Long-lived worker safety

<!-- A stable engineering practice: what we do, one example, and the reason it holds. -->

## Practice

HTTP requests are served by PHP-FPM, where a process lives for one request. Horizon workers
are long-lived: one process handles many jobs in a row, and any state a singleton retains
leaks between them. In a multi-tenant system that leak means one tenant seeing another's
data.

```
MUST NOT: hold the request, the config repository, the tenant context or the current
          assistant in a singleton constructor — directly or transitively through
          another bound service
MUST:     keep mutable request/job state in `scoped` bindings; they are rebuilt for every job
MUST:     switch tenants through TenantSwitcher::runForTenant(), with the restore in `finally`
MUST NOT: write to static properties between jobs
```

This is review-only across the application in general. It is mechanically enforced for the
Flow runtime specifically, by `tests/Architecture/FlowRuntimeIsolationTest.php` (the engine,
handlers, orchestration, registry, routing and state must not touch facades, the container or
`Application`) and `NodeHandlerScopedDependenciesTest` (a handler resolved from the
singleton registry after `forgetScopedInstances()` reaches the scoped message sender, content
translator and `TenantSettings` of the current job, not those of the job that built the registry). See the flow domain
rules for the detail of what those tests check and why a handler registry — itself a
singleton for the worker's lifetime — must never capture a scoped object.

## Example

`app/Domains/Broadcasting/Jobs/RunBroadcastJob.php` takes a tenant id and a broadcast id as
plain constructor arguments (not the tenant itself), then resolves the tenant and runs the
whole handler body inside `TenantSwitcher::runForTenant($tenant, function () { ... })`. Every
tenant-independent service it uses (`TenantRepositoryInterface`, `TenantSwitcher`,
`BroadcastRecipientResolver`) is pulled through the `handle()` method's own dependency injection,
so it is rebuilt fresh for this job rather than reused from a previous one. The tenant-dependent
`TenantSettings` is resolved with `app(TenantSettings::class)` inside the `runForTenant` closure,
after the switch, so it reads the right tenant's settings. `app/Providers/DomainServiceProvider.php`
binds `TenantContextInterface`, `TenantSwitcher` and `CoreBootstrap` as `scoped()`, not
`singleton()`, so the container rebuilds them per job instead of reusing the first job's
instance for the life of the worker process.

## Rationale

A singleton that captures the request, the tenant context or the current assistant is built
once and reused for every job the worker process picks up afterwards. In single-tenant PHP-FPM
that reuse is invisible because the process dies with the request; in a Horizon worker it means
job two silently runs with job one's tenant, config, or assistant still attached — a
cross-tenant data leak that no request boundary catches. `scoped` bindings and
`TenantSwitcher::runForTenant()` make the tenant boundary explicit and give every job a clean
slate.
