---
id: domain-solution
type: domain
status: active
summary: "Installed Solution packages: manifest discovery and validation from composer.json, catalog and cache, platform:update"
domains:
  - solution
topics: []
load: domain
paths:
  - "app/Domains/Solution/**"
  - app/Console/Commands/Platform/UpdatePlatformCommand.php
  - app/Console/Commands/Platform/CacheSolutionsCommand.php
  - app/Console/Commands/Platform/ClearSolutionsCacheCommand.php
  - "tests/*/Domains/Solution/**"
  - "tests/Fixtures/Solution/**"
  - tests/Support/FakeVendor.php
---
# Solution

## Responsibility

Finds the Solution packages installed in this image, checks their manifests and answers which of
them Core may use. A Solution is a Composer package of `type: fapost-solution` whose `composer.json`
carries an `extra.fapost` block (schema 1: `schema`, `id`, `name`, `description`, `provider`,
`actions`). The format, its rules and how it evolves are defined once, in Foundation
(`Fapost\Foundation\Solution\Manifest\ManifestSchema` and `ManifestParser`, a pure function over a decoded
`composer.json`); this domain adds only what needs the installation. See
`adr-20261010-solution-manifest-is-data-in-composer-json` for why.

- `InstalledPackagesReader` reads `vendor/composer/installed.json` (both formats) for the type and
  version, and each package's own `composer.json` for the manifest, so a `dev:link`ed checkout shows
  an edit at once.
- `SolutionManifestValidator` applies the Foundation rules, then checks the provider class exists and
  extends `AbstractSolutionServiceProvider`, and that no two packages claim one `id`. Fail closed: a
  package with a violation, and both packages of a collision, never reach the catalog.
- `InstalledSolutions` is the read-only catalog (`all`, `find`, `has`) that later work builds on
  (activation storage keyed by `id`, the registrar, the activation screen). `CoreInstalledSolutionCatalog`
  answers Foundation's `InstalledSolutionCatalogInterface` from it for operator packages.
- `SolutionCache` compiles the valid Solutions to `bootstrap/cache/fapost-solutions.php`. It is an
  accelerator only: it records size and mtime of installed.json and of each Solution's composer.json,
  a missing or stale file is ignored and the catalog is built in memory, and a request never writes it.
- `SolutionServiceProvider` boots the provider of every valid Solution itself (owner decision, 2026-10-10,
  recorded in the ADR: this task boots providers, but only `register()` bindings run, because
  `AbstractSolutionServiceProvider::boot()` exits without a registrar; action registration comes with
  activation). A `fapost-solution` package may carry no `extra.laravel.providers` entry at all (an empty
  list is fine): the manifest rejects any entry, in any spelling, because package discovery would boot it
  whether or not its manifest holds up.
- `platform:update` (`app/Console/Commands/Platform`): validate, then (unless `--validate-only`)
  `App\Console\Platform\PlatformMigratorInterface` (landlord migrate and `ops:tenants-migrate`; console-level and deliberately outside this domain, which only validates and caches), then write the cache.
  Any violation exits 1 before the database or the cache is touched. `--validate-only` needs no
  database. `solutions:cache` / `solutions:clear` hang on `optimize` / `optimize:clear`; the container
  entrypoint runs `solutions:cache` with the other caches.

## Boundaries

- Depends on Foundation and the framework only; PHPat (`SolutionBoundariesTest`) forbids a dependency
  on any other `App\Domains` namespace, and forbids facades and `Illuminate\Database` outside
  `Infrastructure` and `Providers`, so discovery runs without a database.
- Not here yet: activation storage and the per-tenant switch, `CoreRegistrarInterface`, the
  activation screen, per-phase migrations of a Solution, the `ui` and `migrations` manifest fields.
  Plugins (`type: fapost-plugin`) are reserved and not discovered.
- The Foundation constraint is not re-checked in Core: Composer refuses to install a package whose
  `require["fapost/foundation"]` does not fit the locked version, and a package Composer did not
  install is not in installed.json.
- The catalog depends on the image alone, never on a tenant, so `InstalledSolutions` may live for the
  life of a worker (`conventions/worker-safety.md` is not in play); workers restart after an upgrade.

## Entry points

- `app/Domains/Solution/Providers/SolutionServiceProvider.php`
- `app/Console/Commands/Platform/UpdatePlatformCommand.php`
- Tests: `tests/Unit/Domains/Solution`, `tests/Feature/Domains/Solution`; a throwaway vendor tree is
  built by `tests/Support/FakeVendor.php` from the fixture package in `tests/Fixtures/Solution`.
