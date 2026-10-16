---
id: domain-solution
type: domain
status: active
summary: "Installed Solution packages: manifest discovery and validation, catalog and cache, platform:update, per-tenant activation, the console extension registry and publication of prebuilt console UI"
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
  - app/Console/Commands/Platform/ActivateSolutionCommand.php
  - app/Console/Commands/Platform/DeactivateSolutionCommand.php
  - app/Console/Commands/Platform/ListSolutionsCommand.php
  - app/Console/Commands/Platform/SolutionTenantCommand.php
  - database/migrations/tenant/2026_10_14_000001_create_solution_activation_tables.php
  - tests/Feature/Tenancy/PostgresSolutionActivationTest.php
  - tests/Support/InstallsFakeSolutions.php
  - tests/Support/InMemorySolutionActivationStore.php
  - "app/Infrastructure/Solution/**"
  - "tests/Unit/Infrastructure/Solution/**"
  - tests/Architecture/SolutionBoundariesTest.php
  - "tests/Fixtures/Solutions/**"
  - tests/Support/InstallsShowcaseSolution.php
  - app/Http/Controllers/Admin/SolutionController.php
  - app/Http/Shell/SolutionsPresenter.php
  - "resources/js/pages/Console/AdminSolutions/**"
  - tests/Feature/Console/AdminSolutionsConsoleTest.php
  - config/solutions.php
  - tests/Support/FakeSolutionEntitlement.php
reviewed_at: 2026-10-16
---
# Solution

## Responsibility

Finds the Solution packages installed in this image, checks their manifests and answers which of
them Core may use. A Solution is a Composer package of `type: fapost-solution` whose `composer.json`
carries an `extra.fapost` block (schema 1: `schema`, `id`, `name`, `description`, `provider`,
`actions`, and from Foundation 0.15 the experimental `ui`, the path of its `fapost-ui.json`). The format, its rules and how it evolves are defined once, in Foundation
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
- `SolutionServiceProvider` boots the provider of every valid Solution itself (owner decision, 2026-10-10)
  and, in an `$app->booting()` callback (after every `register()`, before any `boot()`), hands each provider
  a registrar of its own through `registerExtensionsWith()` (`adr-20261014-solution-actions-registered-per-process`).
  The port is `Contracts/SolutionRegistrarFactoryInterface`; the implementation, `SolutionCoreRegistrar`, is in
  `app/Infrastructure/Solution` because it writes into the Flow action registry. It accepts only
  `registerActionHandler`, and only for ids in the Solution's `extra.fapost.actions`; every other call, an
  undeclared id and a duplicate throw at boot. Whether an action may run for a tenant is asked per call through the Flow port
  `ActionAvailabilityInterface` (`solution_inactive`). A `fapost-solution` package may carry no `extra.laravel.providers` entry at all (an empty
  list is fine): the manifest rejects any entry, in any spelling, because package discovery would boot it
  whether or not its manifest holds up.
  It wraps each provider in `booting`/`booted` callbacks that open and close the console registry for that
  Solution's id, and freezes the registry once the application has booted (`solutions.freeze_console_registry`,
  off in `phpunit.xml` only, because tests register fixture Solutions after boot).
- Console UI (`adr-20261014-solution-console-ui`, experimental; rules in `conventions/solution-console-ui.md`):
  - `Console/ConsoleExtensionRegistry` implements Foundation's `ConsoleExtensionsInterface`: a process
    singleton (it depends on the image, never on a tenant) that accepts declarations only inside a Solution
    provider's `boot()`, binds each to that Solution's id, checks names are its own, keys unique per point and
    target, one application per Solution and none for the ids `console`/`admin`; it keeps the `routes()` and
    `assistantRoutes()` closures. Reading it per request (activation, ability, assistant), the routes, the
    shared prop `extensions` and the import map live in `app/Http/Shell` and `app/Http/DataTable`, outside this
    domain, because they need Staff, Assistant and the Gate.
  - `SolutionUiBundleReader` reads `fapost-ui.json` (Foundation's `UiBundleParser`, then the files: each listed
    file exists inside the directory, the directory is not the package root, no file with PHP in its name, no
    link out, only static extensions are published — others are warnings, hidden paths skipped).
  - `SolutionUiPublisher` checks every bundle against the components the registry names (before migrations)
    and copies the allowed files to `public/solutions/<id>/<hash>/` (hash = 12 hex of sha256 of
    `fapost-ui.json`) through a temporary sibling; it keeps the current and the previous build.
  - `PublishedSolutionUi` answers which bundles are published, from `SolutionCache` when fresh, otherwise from the
    files; a changed build is not served before the next `platform:update`.
- Activation per tenant lives in the tenant's schema (`adr-20261014-solution-activation-in-tenant-schema`):
  `solution_activations` (state, one row per Solution) and `solution_activation_events` (append-only
  journal), behind `Contracts/SolutionActivationStoreInterface` and `Infrastructure/EloquentSolutionActivationStore`.
  - `ActiveSolutions` (`isActive`, `ids`) is the only source of the effective state (raw rows and the journal are read through the store port: `all()`, `events()`). Bound `scoped`, memoised per tenant id,
    one lazy indexed query. Active = stored `active` AND installed with a valid manifest AND entitled by the
    operator, so a removed package, a broken manifest or a lost entitlement makes a Solution inactive with no
    write; reading never writes. `isEntitled()`/`entitlement()` tell screens and commands why one does not run.
  - Entitlement (amendment of the activation ADR, 2026-10-16): Foundation's `SolutionEntitlementInterface` is asked
    only by the `scoped` `Services/SolutionEntitlements` (memo per tenant id, one question per tenant and scope, an
    exception reported once and remembered as unavailable). Default `AllSolutionsEntitled` (`bindIf`). Fail open in
    `ActiveSolutions` (the factor is left out), fail closed in `SolutionActivator::activate`
    (`SolutionEntitlementUnavailableException`); a Solution not entitled is refused for every actor
    (`SolutionNotEntitledException`). The operator is not asked when the tenant has nothing stored active and installed.
  - `SolutionActivator` (`activate`, `deactivate`) is the only writer, with an `ActivationActor` (`user`,
    `console`, `operator`, `system`). Idempotent; row and journal entry in one transaction under a row
    lock; activating a Solution that is not installed throws `SolutionNotInstalledException` (not entitled:
    `SolutionNotEntitledException`; entitlement unreadable: `SolutionEntitlementUnavailableException`),
    deactivating one is allowed; it then drops its tenant's memo. Default: nothing active.
  - `solutions:activate|deactivate|list` need `--tenant=<slug>` and run inside `runForTenant()`.
    The `onActivate`/`onDeactivate` hooks are not called yet.
  - The activation screen is `admin/solutions` (`Admin\SolutionController`, props from `Http/Shell/SolutionsPresenter`,
    page `Console/AdminSolutions`), behind `manage_settings` like tenant settings and usage. It lists only valid Solutions
    (a failing manifest never reaches the catalog, and `platform:update` refuses it first) plus stored-active rows whose
    package is gone ("no longer installed", which can only be turned off). Activate and deactivate are POSTs through
    `SolutionActivator` with `ActivationActor::user(id, name)`, so the journal names the staff user; deactivation asks for
    confirmation and says the data is kept. What a Solution "registers" is its manifest actions plus its declarations in
    `ConsoleExtensionRegistry`, shown whether or not it is on. `SolutionsPresenter::isActivatable()` is the one place the
    entitlement step (`solution-entitlement-seam`) will be asked what a tenant may have; the controller checks it again.
  - `SolutionUiCompatibility` follows `adr-20261014-solution-console-ui` (Stability): the `kit` range a bundle declares
    (kept on `SolutionUiBundle`, with `vue` and `inertia`; null in a cache older than that) is checked with `composer/semver`
    (a direct dependency since 2026-10-16, owner-approved by the ADR) against `config/solutions.php: console_runtime`;
    Vue and Inertia are checked by major, i.e. whether the range admits any version of the shipped major. A range the
    library cannot read counts as a mismatch. `ConsoleRuntimeConfigTest` holds the config equal to `package-lock.json`.
    A mismatch makes `SolutionsPresenter::isActivatable()` false: the card shows the reason beside a disabled button, the
    controller refuses a direct POST with a flashed reason, and `platform:update` warns (exit 0). Turning off is always
    allowed. Not done: an already-active Solution whose range fails after a Core upgrade is still served its UI, because
    `SolutionUi::isServable()` still passes every bundle (the ADR says it should not; `first-solution` phase 0).
- `platform:update` also checks that every action a valid manifest declares is registered in the booted
  `ActionHandlerRegistry` (`<package>: extra.fapost.actions[i]: "<id>" is declared but not registered`, exit 1), after the manifest and console UI checks and before any migration.
- `platform:update` (`app/Console/Commands/Platform`): validate the manifests and the console UI bundles, then
  (unless `--validate-only`) `App\Console\Platform\PlatformMigratorInterface` (landlord migrate and `ops:tenants-migrate`; console-level and deliberately outside this domain, which only validates, publishes and caches), then publish the bundles, then write the cache with them.
  Any violation exits 1 before the database or the cache is touched. `--validate-only` needs no
  database. `solutions:cache` / `solutions:clear` hang on `optimize` / `optimize:clear`; the container
  entrypoint runs `solutions:cache` with the other caches.

## Boundaries

- Depends on Foundation, the framework and `App\Domains\Tenancy\Contracts` (for the current tenant);
  PHPat (`SolutionBoundariesTest`) forbids a dependency on any other `App\Domains` namespace, and
  forbids facades and `Illuminate\Database` outside `Infrastructure` and `Providers`, so discovery
  and the services run without a database.
- Not here yet: registrar methods other than `registerActionHandler`, per-phase migrations of a Solution, the `migrations` manifest field, and hiding the UI of an active Solution whose kit range fails (`SolutionUi::isServable()` is the seam; `first-solution` phase 0).
  Plugins (`type: fapost-plugin`) are reserved and not discovered.
- The Foundation constraint is not re-checked in Core: Composer refuses to install a package whose
  `require["fapost/foundation"]` does not fit the locked version, and a package Composer did not
  install is not in installed.json.
- The catalog depends on the image alone, never on a tenant, so `InstalledSolutions` may live for the
  life of a worker; workers restart after an upgrade. Activation is the opposite: it depends on the
  tenant, so `ActiveSolutions` is `scoped` and keyed by tenant id (`conventions/worker-safety.md`), and
  `SolutionActivator`/the store are plain `bind`s with no state.

## Entry points

- `app/Domains/Solution/Providers/SolutionServiceProvider.php`
- `app/Console/Commands/Platform/UpdatePlatformCommand.php`
- `app/Domains/Solution/Services/ActiveSolutions.php`, `SolutionActivator.php`
- `app/Console/Commands/Platform/ActivateSolutionCommand.php`, `DeactivateSolutionCommand.php`, `ListSolutionsCommand.php`
- `app/Http/Controllers/Admin/SolutionController.php`, `app/Http/Shell/SolutionsPresenter.php`, `resources/js/pages/Console/AdminSolutions/`
- `app/Domains/Solution/Console/ConsoleExtensionRegistry.php`, `Services/SolutionUiPublisher.php`
- Tests: `tests/Unit/Domains/Solution`, `tests/Feature/Domains/Solution`; a throwaway vendor tree is
  built by `tests/Support/FakeVendor.php` from the fixture package in `tests/Fixtures/Solution`. The console
  fixture `showcase` (every point, both route kinds, a committed hand-written `dist/`) is
  `tests/Fixtures/Solutions/Showcase`, installed by `tests/Support/InstallsShowcaseSolution.php`.
- A real Composer-installed Solution lives outside Core: `fapost-lab/solution-hello-world` (id `hello`, one action,
  one console page from a committed `dist/`), installed by the composer overlay and tested inside Core by its own CI on
  Core's main and latest release. It is the check that the overlay → installed.json → manifest → provider → bundle path
  works for a package Core never names; the fixtures above cannot prove that, because FakeVendor builds the vendor tree.
  Its tests still import `App\…` (no Solution testing kit in Foundation yet) — a recorded gap, not a pattern.
- The lifecycle proof is one suite, `tests/Feature/Domains/Solution/SolutionLifecycleTest.php`: the fixtures `feedback` (actions)
  and `showcase` (console UI) installed together, `platform:update --validate-only` and the full update, activation for
  tenant A, a real flow whose `call` node reaches the action through the FlowEngine, the builder's test call, the
  console pages, tenant B answering `solution_inactive` throughout, deactivation, reactivation and a worker-like
  sequence with `forgetScopedInstances()` between jobs. On PostgreSQL tenant B has a real schema and the Eloquent
  store is used; SQLite has no schemas, so the scenario there uses `InMemorySolutionActivationStore`, and a second
  test drives the same chain through `solutions:activate|deactivate` on the real store. Change the chain (a new
  link, a new refusal) and extend that suite, not only the unit test of the link.
