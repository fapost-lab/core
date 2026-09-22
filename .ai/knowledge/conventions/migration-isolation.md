---
id: convention-migration-isolation
type: convention
status: active
domains: []
paths:
  - "database/migrations/**"
  - tests/Architecture/MigrationTest.php
  - tests/Unit/Architecture/MigrationTest.php
summary: "Migrations are pure DDL: no app state, tenant context, feature branching, or cross-module table access; PHPat enforces the App Domains / App Services dependency part."
---
# Migration isolation

<!-- A stable engineering practice: what we do, one example, and the reason it holds. -->

## Practice

A migration is a DDL operation. `up()` / `down()` must not depend on runtime state.

```
MUST NOT: call app(), config() or env() to make a runtime decision
MUST NOT: use TenantContext::get() or any tenant-aware service
MUST NOT: branch on feature/module activation
MUST NOT: seed data that depends on runtime state
MUST NOT: DB::table() over another module's tables from a module's migration
```

`tests/Architecture/MigrationTest.php` (a PHPat rule, registered in `phpstan.neon`) enforces
the part it can check mechanically: a class under `database/migrations/` must not depend on
`App\Domains` or `App\Services`. `app()`, `config()`, `env()` and cross-module `DB::table()`
calls are not caught by that rule — they stay review-only. The default PHPUnit run still
covers the PHPat check, through `tests/Unit/Architecture/MigrationTest.php`, which runs
PHPStan; run `php artisan test tests/Architecture` directly and it reports success while
checking nothing, because those classes are not PHPUnit `TestCase`s.

Keep the PHPat rule's `->because()` text and this document in sync — the rule exists to
enforce what this convention describes, and a passing PHPat run is only meaningful evidence
for the parts of this list it actually encodes.

## Example

`database/migrations/2026_03_19_165425_create_presale_requests_table.php` is a clean
instance: `Schema::create()`/`Schema::dropIfExists()` only, no service or tenant lookups, no
branching on runtime state. Driver-specific DDL is fine when it branches on the connection
itself (e.g. `Schema::getConnection()->getDriverName()` in
`database/migrations/tenant/2026_03_26_130001_add_status_phone_to_staff_users_table.php`
to choose the right `ALTER COLUMN ... DROP NOT NULL` syntax for Postgres) — that is a
property of the schema being migrated, not of the running application or tenant.

## Rationale

A migration runs once, outside any request or job, often before the application container
that would resolve those services even exists in that form. A migration that reaches into
`App\Domains`, tenant context or feature flags ties schema changes to code that changes
independently and to state that isn't there yet at migrate time — it can pass in one
environment and break in another, or silently do the wrong thing for a tenant that isn't
current when the migration runs. Keeping migrations pure DDL makes them replayable and
reviewable without running the application.
