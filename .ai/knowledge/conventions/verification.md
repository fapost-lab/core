---
id: convention-verification
type: convention
status: active
domains: []
paths: []
stages:
  - verify
summary: Which composer/make commands run the PHPUnit and PHPat suites, and why php artisan test tests/Architecture verifies nothing
---
# Verification commands

## Practice

- `composer test` runs the PHPUnit suites from `phpunit.xml` in parallel
  (`php artisan test --parallel`, SQLite in memory); plain `php artisan test` runs
  them in one process. Add `--recreate-databases` after changing a migration.
- The `redis` group (`tests/Feature/Redis`) needs a reachable Redis from `.env`;
  `composer run test:redis` runs only it.
- `composer run test:arch` runs the PHPat architecture rules through PHPStan
  (`vendor/bin/phpstan analyse --configuration phpstan.neon`). PHPat rules live in
  `tests/Architecture`.
- The default PHPUnit run covers PHPat too, through
  `tests/Unit/Architecture/MigrationTest.php`, which runs phpstan.
- Do not run `php artisan test tests/Architecture` as the PHPat check: those classes
  are not PHPUnit `TestCase`s, and the command reports success while verifying
  nothing.
- `make` lists the wrapped commands (`make test`, `make test-arch`/`make stan`,
  `make test-filter FILTER=...`); `CONTAINER` in `.make.local` routes them into a
  container.

## Example

`composer run test:arch` runs
`vendor/bin/phpstan analyse --configuration phpstan.neon --memory-limit=512M
--no-interaction`, which is what both `make test-arch` and `make stan` (its alias)
invoke. CI (`.github/workflows/ci.yml`) runs the same checks on every pull request,
plus `go test` for the gateway and type-check/Vitest/build for the frontend. The
PHPUnit suite itself runs twice there: once on SQLite, matching a local run, and
once on PostgreSQL 15, where schema-per-tenant switching, partitioned tables and
`uuid` columns are exercised for real.

## Rationale

The PHPat suite is wired through PHPStan, not PHPUnit, so `php artisan test` only
looks like it covers architecture rules — the classes under `tests/Architecture` are
plain PHPStan rule definitions, and running them as PHPUnit tests silently skips
every assertion while reporting green. Naming the exact commands and the trap
together stops that false confidence from resurfacing after a migration or a new
architecture rule is added. Running the PHPUnit suite on both SQLite and PostgreSQL
in CI catches multi-tenant schema behaviour that SQLite cannot exercise, without
forcing every local run to pay for a Postgres instance.
