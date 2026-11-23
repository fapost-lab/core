---
id: adr-20261123-builder-mode-usage-for-operators
type: adr
status: accepted
date: 2026-11-23
domains:
  - staff
  - tenancy
paths:
  - app/Domains/Tenancy/Infrastructure/CoreBuilderModeUsage.php
  - app/Domains/Staff/Services/BuilderModePreference.php
summary: Why operator packages read builder mode usage through Foundation's BuilderModeUsageInterface (staff-limit scope, implemented by Core with Staff's count passed as a closure) plus an experimental BuilderModeChanged event, and not an analytics_events row
reviewed_at: 2026-11-23
---
# Operator packages read builder mode usage through a Core-implemented Foundation contract and may follow switches through an event

## Context

Spec `flow-automations`, phase 8: the SaaS shell wants to see which layout of the flow builder (tree or graph,
`adr-20261123-flow-builder-graph-mode`) a tenant's staff use. The mode is a per-user column, `users.builder_mode`, in
the tenant's schema, which an operator package may not read, and `null` there means the tree. The spec named "a Core
analytics event". Approved at the design gate by the owner ("Да, все по рекомендациям", 2026-11-23): the recommended
option of every open decision.

## Decision

- **Snapshot contract (Foundation 0.47, experimental).** `Analytics\Contracts\BuilderModeUsageInterface::usageFor($tenantId)`
  returns `Analytics\DTO\BuilderModeUsage` (`tree`, `graph`, derived `total`), or null for an unknown, malformed or
  Pending tenant. It counts who the `staff` limit counts (`User::scopeCountedForLimit()`: not deactivated, invited
  included, without the platform support user), so `total` equals `TenantUsageInterface`'s `staff`; `null` counts as
  tree. Core implements it in `Tenancy\Infrastructure\CoreBuilderModeUsage` inside `TenantSwitcher::runForTenant()`,
  bound with a plain `bind` (Core is the only implementation). The caller caches; null is "not reported".
- **The count belongs to Staff and reaches Tenancy as a closure.** `User::countByBuilderMode()` (one aggregate query)
  is handed to `CoreBuilderModeUsage` by `DomainServiceProvider`, so Tenancy gains no import of Staff — the shape of
  `TenantUsageCounters`.
- **Switch event (Foundation 0.47, experimental).** `Analytics\Events\BuilderModeChanged` (`tenantId`, `mode`,
  `previousMode` as `'tree'|'graph'`, `occurredAt`; no user) is dispatched by `Staff\Services\BuilderModePreference`
  after the choice is saved, only when the mode actually changed (`null` counts as tree). Modes travel as strings: Core's
  `BuilderMode` enum stays in Core.
- **Nothing is written to Core's `analytics_events`.**

## Alternatives

- **Event only, the shell keeps counters.** Misses everyone who never switched and everyone who switched before the
  release, and deactivations and deletions fire nothing, so the counters drift. Rejected as the only mechanism.
- **Snapshot only.** Exact, but leaves no trend and departs from the spec's wording. Fallback had the owner not wanted
  the event.
- **A new `AnalyticsEventType` through `AnalyticsWriterInterface`.** The row lands in the tenant's `analytics_events`,
  which the shell may not read; reaching it would mean the shell rebinding Core's writer. Rejected.
- **The implementation in Staff, or Tenancy importing `User`.** Landlord reads belong to Tenancy, and Tenancy takes no
  outward dependency; the closure keeps both.

## Consequences

- A dashboard over all tenants costs one tenant switch and one query per tenant per call; the shell caches or snapshots.
- A third builder mode needs a Foundation change first: `BuilderModeChanged` refuses any mode but tree and graph.
- The event is dispatched synchronously in the request, like `TenantDecommissioned`; a listener that throws turns the
  saved choice into an error response.
- Both types are experimental and can be removed in a minor release while the shell does not build on them.
- The SaaS panel screen is a separate task in `packages/fapost-saas`.
