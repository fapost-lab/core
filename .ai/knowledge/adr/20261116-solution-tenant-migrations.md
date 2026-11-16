---
id: adr-20261116-solution-tenant-migrations
type: adr
status: accepted
date: 2026-11-16
domains:
  - solution
  - tenancy
paths:
  - "app/Domains/Solution/**"
  - "app/Infrastructure/Solution/**"
  - "app/Jobs/Solution/**"
  - app/Console/Commands/Platform/MigrateSolutionsCommand.php
  - app/Console/Platform/ArtisanPlatformMigrator.php
  - database/migrations/tenant/2026_11_16_000001_create_solution_schema_tables.php
summary: A Solution declares tenant migrations in extra.fapost.migrations (Foundation 0.38); journaled in solution_migrations/solution_schemas, one transaction per Solution per tenant; run on activation, by platform:update only where present or stored active (readiness by a digest of the file names), and queued when an active Solution is not ready (solution_not_ready); landlord paths from extensions refused
---
# A Solution declares tenant migrations in its manifest; they run where it runs and are kept current where they already are

## Context

`adr-20261014-solution-actions-registered-per-process` refused `registerMigrations` for a Solution until its rules
were decided, and the Solution overview said a Solution must not rely on activation for per-tenant setup. The
`extension-installer` spec (owner, 2026-10) then chose: Solution migrations are expand-only, applied in a tenant's
schema when that tenant activates the Solution and on upgrade only where it is active — not across every tenant on
install, because the deploy window would grow with the tenant count. Three facts complicate "on activation": a
Solution the operator grants (`SolutionGrant`) runs with no stored row and no activation moment; there was no record
of a Solution's schema per tenant; and the landlord migration runs without `--path`, so a `loadMigrationsFrom()` in a
Solution provider would have run on the landlord. Decided at the gate of task `solution-tenant-migrations`, 2026-11-16.

## Decision

- **Declared in the manifest.** Foundation 0.38 adds the optional schema-1 field `extra.fapost.migrations`: a directory
  relative to the package root (shape checked by Foundation's parser). Core checks it exists inside the package and
  records the newest file name in it as `InstalledSolution::$latestMigration` (in the compiled catalog too).
  `registerMigrations` stays refused by `SolutionCoreRegistrar`; Foundation's `declareSolutionMigrations()` is deprecated.
- **Journaled apart from Core.** Two tenant tables: `solution_migrations` (one row per applied migration, keyed by
  Solution id and file name) and `solution_schemas` (one row per Solution — its footprint in the schema — with the
  newest migration applied and the digest of the package's migration names the schema was last brought up to). Core's
  `migrations` table is not used: two Solutions may ship files of the same name, and batches would mix with Core's.
- **One transaction per Solution per tenant.** `DatabaseSolutionMigrator` runs every pending migration, the journal and
  the footprint in one transaction on the tenant connection, with a PostgreSQL advisory lock per schema and Solution.
  PostgreSQL DDL is transactional, so a failure leaves the schema as it was. Only `up()` runs; there is no rollback.
  A migration file must return a `Migration` instance that names no connection. Non-transactional statements
  (`CREATE INDEX CONCURRENTLY`) are therefore not allowed.
- **When they run.** `SolutionSchemas::bringCurrent()` is the one entry:
  - activation (`SolutionActivator::activate`, also for a granted Solution) migrates synchronously before the row is
    stored; a failure refuses the activation (`SolutionMigrationFailedException`);
  - `platform:update` runs `solutions:migrate` after the tenant migrations (also when some tenant failed them):
    every active tenant, only the Solutions with a footprint in that schema (deactivated ones included) or stored
    active there, never asking the operator about entitlement;
  - a Solution that is active but not current is **not ready**. Current means the schema's recorded digest equals the
    installed package's (`InstalledSolution::$migrationsDigest`, the sha256 of its sorted file names): the whole set is
    compared, not the newest name, so a file that sorts before one already applied still runs. `ActiveSolutions::isReady()`
    reads `solution_schemas` once per tenant and scope. Not ready: flow steps and calls take `error` with
    `solution_not_ready`, console pages answer 503 "preparing", and `SolutionPreparation` queues
    `PrepareSolutionSchemaJob` (unique per tenant and Solution, queue `solutions.migrate` on `redis-long`, a Horizon
    supervisor of its own so it never waits behind a tenant removal); the admin Solutions screen asks the same way.
    No DDL runs on a step or a page request;
  - `solutions:migrate --tenant=<slug>` prepares the active (granted included), stored-active and present Solutions
    of one tenant at once (for an operator, or the SaaS shell right after a grant).
- **Expand-only, by review.** A Solution's migrations only add (tables named `<id>_…`, columns, indexes); nothing
  enforces it mechanically. Deactivation keeps the tables and they stay current.
- **No extension migrates the landlord.** `platform:update` refuses (exit 1, before any migration) a path registered
  with Laravel's migrator that lies inside an installed Solution or connector package.

## Alternatives

- Migrate every tenant on install — rejected by the `extension-installer` spec: the upgrade grows with the tenant count.
- Run DDL lazily on the first step or request that needs it — locks and timeouts on the hot path.
- A periodic reconcile over every tenant — it would ask the operator's entitlement for every tenant on every tick.
- `registerMigrations` through the registrar — needs the provider booted, against the compiled registry and lazy
  providers the installer spec plans.
- Core's `migrations` table — file-name collisions between Solutions and mixed batches.
- Asynchronous activation — on a first activation the tables are new and empty, so a synchronous run is cheap.

## Consequences

- A granted Solution, and a tenant whose upgrade run failed, answer `solution_not_ready` until the queued preparation
  runs; flows without an `error` branch fail meanwhile. The SaaS shell can avoid the wait with `solutions:migrate`.
- A heavy migration (a data copy) would hold the activation request and its row lock; the expand-only rule is what keeps
  activation cheap, and it is review-only.
- `pgvector` is not reachable from a tenant migration: the tenant `search_path` holds only the tenant schema. The AI
  Solution's knowledge bases must settle that as an installation step.
- Supersedes the `registerMigrations` refusal of `adr-20261014-solution-actions-registered-per-process` as the way a
  Solution gets tables; the registrar still refuses the call.
