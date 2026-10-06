---
id: adr-20261005-extension-packages-own-landlord-tables
type: adr
status: accepted
date: 2026-10-05
domains: []
paths:
  - "database/migrations/landlord/**"
  - app/Providers/AppServiceProvider.php
  - "app/Console/Commands/Platform/Install*.php"
  - app/Console/Commands/MigrateSmartCommand.php
summary: Packages may own prefixed landlord tables; migrations load via loadMigrationsFrom, applied by migrate --database=landlord without --path
---
# Extension packages may own landlord tables of their own, applied by Core's landlord migrate command without a path

## Context

Core's rule was that the `landlord` connection is opened only inside Tenancy. It was written before
any package had platform-level data of its own. The SaaS operator package (closed, outside this
repository) keeps plans, subscriptions and usage per tenant; a paid Solution may need the same. Such
data is not tenant data — it outlives a tenant's schema and spans tenants — so it belongs in
landlord. Core must still never depend on such a package.

Landlord migrations were applied with a narrow command,
`migrate --database=landlord --path=database/migrations/landlord`, in the self-hosting docs, the
Makefile and both install commands. An explicit `--path` makes Laravel ignore every path registered
with `loadMigrationsFrom()` — a package's migrations would never run — and it also skips Core's own
root migrations (`presale_requests`, `cache`, `jobs`, `telescope_entries`).

Dropping `--path` has one hazard: spatie/laravel-settings registers `database/settings` with the
migrator too (config `settings.migrations_paths`). Those migrations belong to tenant schemas and
run through `MigrationScope::settings()`; on `landlord` they would create a `settings` table with
tenant groups and run twice from then on. Core therefore sets `migrations_paths` to `[]`.

## Decision

- An extension package may own landlord tables, all named with its own prefix (`saas_` for the SaaS
  package). Only its migrations create them; only its code reads and writes them.
- It never writes Core's landlord tables (`tenants`, `webhook_registry`) and puts no foreign key on
  them; it refers to a tenant by id and cleans up its own rows. Core never reads a package's tables.
  Anything a package needs from Core's landlord data goes through a Foundation contract.
- Inside Core the rule is unchanged: the `landlord` connection is opened only within Tenancy.
- A package delivers its migrations with `loadMigrationsFrom()` in its service provider; each of its
  landlord migrations declares `protected $connection = 'landlord'` and is pure DDL, like Core's.
- The platform migrate command is `php artisan migrate --database=landlord --force` — the same as
  before, without `--path`. Keeping `--database=landlord` keeps the migration log where every
  existing install has it; dropping `--path` makes it apply Core's root migrations,
  `database/migrations/landlord` (registered by `AppServiceProvider`) and every path a package
  registered. A migration that names a connection runs there; one that names none runs on
  `landlord`. Tenant schemas are still migrated by `ops:tenants-migrate`.

## Alternatives

- Publishing a package's migrations into `database/migrations/landlord` (`vendor:publish`) —
  rejected: copies of a closed package's migrations would sit in the open repository's working
  tree, and every package update would need a new publish; a forgotten one surfaces as missing
  tables in production.
- A Foundation registry of landlord migration paths plus a dedicated `migrate:landlord` command —
  rejected: Laravel's `loadMigrationsFrom()` already is that registry.
- Plain `php artisan migrate --force` — rejected: it keeps the migration log on the default
  connection, so an install whose landlord lives in a separate database would not find the record of
  its landlord migrations and would try to create `tenants` again.
- Each package migrating itself with its own command — rejected: every extension would add a deploy
  step to remember.
- Keeping all extension data in tenant schemas — rejected: subscriptions and usage must survive and
  span tenant schemas.

## Consequences

- With one database (the default, and what every self-hosting doc describes) nothing runs twice
  and nothing fails: there is one migration log. Installs that never ran Core's root migrations
  get them.
- A migration that names its own connection runs there, not on `landlord`: Telescope's
  (`telescope.storage.database.connection`, the default connection) creates `telescope_entries`
  on the default connection while its record goes to the landlord log.
- With a separate landlord database: if the root migrations were ever run by a plain `migrate` on
  the default connection, the landlord log lacks them and `telescope_entries` fails with "already
  exists" — record that migration or drop the table once. Root tables read through the default
  connection (`cache`, `jobs`, `failed_jobs`, `presale_requests`) would be created in the landlord
  database, where nothing reads them; see task `tenant-schema-on-tenant-connection`.
- A package's tables and Core's share one landlord database; prefixes keep them apart, and review
  keeps packages off Core's tables.

Source: spec `tenant-quotas` (Core), task `landlord-ownership-adr`, decided with the owner on
2026-10-05.
