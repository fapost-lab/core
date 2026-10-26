---
id: adr-20261023-operator-packages-own-landlord-tables
type: adr
status: accepted
date: 2026-10-23
domains: []
paths:
  - "database/migrations/landlord/**"
  - app/Providers/AppServiceProvider.php
summary: Installation operator packages (SaaS and monitoring) may own landlord tables; Plugin and Solution still may not; tenant data deletion never touches them; a separate database was rejected
---
# Installation operator packages may own landlord tables

## Context

`adr-20261005-extension-packages-own-landlord-tables` (amended 2026-10-08) limits landlord-table
ownership to the SaaS operator package. The monitoring package (`fapost/monitoring`, design approved
2026-10-23) hosts Laravel Pulse, which stores `pulse_values`, `pulse_entries` and `pulse_aggregates`.
That data describes the installation — requests, queues, servers, exceptions across all tenants — not
any one tenant, so it cannot live in a tenant schema. The table names are fixed by Pulse; the
`saas_`-style prefix rule cannot hold for them.

## Decision

- Landlord-table ownership belongs to **installation operator packages**: packages installed by the
  operator of the installation, not owned by a tenant and neither Plugin nor Solution. Today these are
  the SaaS operator package and the monitoring package. Plugin and Solution packages still have no
  landlord access (ADR-06).
- Prefix rule, refined: a package's landlord tables carry its own prefix (`saas_`) **or**, where a
  third-party library fixes the names, exactly the tables that library creates. For monitoring these
  are the three `pulse_*` tables, created by one migration declaring `protected $connection = 'landlord'`,
  pure DDL (`conventions/migration-isolation.md`), delivered with `loadMigrationsFrom()`.
- Everything else in the earlier ADR stands for each such package: no writes to `tenants` or
  `webhook_registry`, no foreign keys on them, a tenant referred to by id, Core never reads or
  depends on the package's tables, and the path-less `migrate --database=landlord --force`.
- These tables are about the installation. Deleting or purging a tenant's data does not touch them;
  retention is the package's own (Pulse trims by its own schedule).
- Core code is unchanged: inside Core the `landlord` connection is still opened only within Tenancy.

## Alternatives

- A separate database for Pulse — rejected: a second connection to provision, back up and monitor in
  every deployment. The package's configuration key still allows pointing Pulse elsewhere.
- Pulse tables in each tenant schema — rejected: installation-wide data would be split per tenant and
  lost with the schema.
- Opening landlord to any extension package — rejected: Plugin and Solution code is third-party and
  tenant-scoped; ADR-06 stays.

## Consequences

- Pulse tables share the landlord database with Core's and the SaaS package's; the `pulse_` prefix is
  not owned by Core, and review keeps other packages off it.
- The rule stays review-only; no PHPat or architecture test forbids a non-SaaS package's landlord
  migration, so none changed.

Source: task `adr-monitoring-landlord-tables`, spec `installation-health` phase 3; decided by the
owner (delegated to the cycle orchestrator) on 2026-10-23.
