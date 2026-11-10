---
id: convention-verification
type: convention
status: active
domains: []
paths: []
stages:
  - verify
summary: Which composer/make commands run the PHPUnit and PHPat suites, and why php artisan test tests/Architecture verifies nothing
reviewed_at: 2026-11-10
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
- Core's tests run against Core alone, even in a checkout where `composer overlay` installed
  operator packages or Solutions into `vendor/`. `Tests\TestCase::createApplication()` leaves out
  every package installed but not listed in Core's `composer.lock` (`Tests\Support\OverlayPackages`):
  Laravel's package discovery ignores them (`Tests\Support\CorePackageManifest`, built in memory,
  never written to `bootstrap/cache/packages.php`), Solution discovery is given them as
  `InstalledPackagesReader`'s ignored packages, and the Solution cache is read from
  `bootstrap/cache/fapost-solutions.testing.php`, not the developer's `fapost-solutions.php`.
  The extension testing kit (`testing/`) does not do this: an extension's tests need its own package
  discovered.
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

Lock membership is what tells an overlay package: `tools/composer-overlay.php` refuses any package
Core requires or locks and writes its own `composer.local.lock`, so an overlay package and every
dependency it brought in are exactly what `installed.json` holds beyond `composer.lock`. Filtering by
`fapost/*` or by `composer.overlay.json` would miss those dependencies (Pulse, Telescope) and their
providers. In CI nothing is installed beyond the lock, so the filter is empty there. Running Core's
tests on a second, overlay-free `vendor/` was rejected: a second install to keep in step, and a plain
`php artisan test` in the developer's checkout would still fail.
