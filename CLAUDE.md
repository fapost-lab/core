# CLAUDE.md - FaPost Core Agent Rules

Context for Claude Code and other coding agents. Read before starting a task.

This file is not a roadmap and does not track implementation status. It holds the
durable rules of the codebase: architectural constraints, coding conventions, and
where the authoritative documentation lives. Do not add checklists, plans, future
tables or product promises here; a status change belongs in `docs/platform/`.

## Project Context

FaPost Core is the platform for conversational assistants and flow automation.
This repository contains the platform itself, without niche Solution packages.

- https://docs.fapost.in is the single source of truth. Sources are in `docs/site/`.
- `docs/INDEX.md` indexes the internal documentation under `docs/`.
- `drafts/CURRENT_TASK.md` is the current operational focus and the only file `drafts/` may contain.

## Stack

- PHP 8.4, Laravel 12
- PostgreSQL with landlord / tenant connections (schema per tenant)
- Redis for cache, queues, locks and the hot-path registry
- Horizon queues
- Go webhook gateway (`gateway/`), an optional ingress in front of PHP
- Filament admin
- Inertia + Vue flow builder
- PHPUnit 12, PHPat/PHPStan architecture checks

## Directory Boundaries

```text
app/
  Domains/          Technical bounded contexts: Tenancy, Flow, Messaging, Contact, Assistant, Channels, Media, Conversation, Broadcasting, Staff.
  Filament/         Admin UI.
  Http/             Controllers, middleware, builder endpoints.
  Jobs/             Cross-domain orchestration jobs.
  Providers/        Laravel service providers.

database/migrations/
  landlord/         Platform / landlord schema.
  tenant/           Tenant schema.

packages/
  fapost-foundation Public contracts and DTOs for Core/Solutions/Plugins (separate repository, git-ignored here).
  fapost-support    Shared primitives (separate repository, git-ignored here).

resources/js/builder/
  Vue flow builder.
```

Do not create new base folders without an explicit decision. In particular,
`app/Features` and Solution folders do not exist until they are actually added
to the code.

## Workflow

- `main` is the only long-lived branch. Work happens on short-lived branches
  (`feat/`, `fix/`, `chore/`, `docs/`, `refactor/`) and reaches `main` through
  a squash-merged pull request. Releases are `v*` tags on `main`.
- Commit messages and pull request titles use Conventional Commits:
  `type(scope): subject`. Full rules: https://docs.fapost.in/contributing/commits
- Do not commit or push unless explicitly asked to.
- Process documentation: `CONTRIBUTING.md` (short), `docs/site/contributing/` (full).

## Laravel And PHP Rules

- Follow the patterns of neighbouring files.
- Every `.php` file starts with `declare(strict_types=1);`.
- `final class` by default.
- Constructor property promotion and explicit return types.
- Enum cases in TitleCase.
- PHPDoc for meaning, array shapes and generics; inline comments only for genuinely complex logic.
- Prefer `php artisan make:* --no-interaction` for new Laravel artifacts where it applies.
- Do not add dependencies without agreement.
- After changing PHP, run `vendor/bin/pint --dirty --format agent`.
- Cover every code change with a minimal relevant test and run that test.
- Create documentation files only when the user explicitly asks.

## Tenant-Aware Execution

The tenant is the base coordinate of the runtime. Core runtime code must fail
fast when a tenant context is required and not set.

Forbidden:

- Branching on the deployment shape inside Core runtime.
- `if (isSingleTenant())` and similar checks.
- Falling back to a "default tenant" instead of an explicit tenant context.
- Direct landlord lookups from domains outside `Tenancy`.

Allowed landlord access pattern: domains depend on a contract from
`Tenancy/Contracts`; direct `DB::connection('landlord')` stays inside Tenancy
infrastructure.

## Migration Isolation

A migration is a DDL operation. `up()` / `down()` must not depend on runtime state.

Forbidden:

- `app()`, `config()`, `env()` for runtime decisions.
- `TenantContext::get()` and tenant-aware services.
- Branching on feature/module activation.
- Seed data that depends on runtime state.
- `DB::table()` over another module's tables from a module's migration.

The PHPat rules must match the text of this section. They run through
`phpstan.neon`; the default PHPUnit run covers them via
`tests/Unit/Architecture/MigrationTest.php`.

## Long-Lived Worker Safety

HTTP requests are served by PHP-FPM, where a process lives for one request.
Horizon workers are long-lived: one process handles many jobs in a row, and any
retained state leaks between them. In a multi-tenant system that leak means one
tenant seeing another's data.

Rules:

- Do not hold the request, the config repository, the tenant context or the current assistant in a singleton constructor.
- Keep mutable request/job state in `scoped` bindings; they are rebuilt for every job.
- Switch tenants through `TenantSwitcher::runForTenant()` with the restore in `finally`.
- Do not write to static properties between jobs.

## ID Strategy

- Tenant-schema primary keys: ULID stored in a PostgreSQL `uuid` column.
- Use `Fapost\Support\Concerns\HasUlidPrimaryKey` when a model follows this strategy.
- Migrations: `$table->uuid('id')->primary()` without a database default.
- Foreign keys: `foreignUuid(...)->constrained()->cascadeOnDelete()` or the local equivalent in the existing style.
- Do not change special public identifiers such as the webhook public hash without a separate decision.

## Dependency Direction

`packages/fapost-foundation` and `packages/fapost-support` do not depend on Core.

Forbidden:

- `use App\...` inside foundation/support.
- References from foundation/support to concrete Core domain classes.
- Moving Core business logic into support.

A contract needed by external Solutions/Plugins belongs in foundation. A pure
reusable primitive with no Core dependency belongs in support. Anything used by
one domain and carrying domain semantics stays in Core.

## Domain Code Rules

- Controllers and Jobs only orchestrate; business logic lives in services and domain classes.
- Domain services do not use `app()`, `resolve()` or global Laravel helpers as hidden dependencies.
- Use repositories/ports where a domain crosses a persistence boundary or another bounded context.
- Facades are acceptable in the infrastructure layer: providers, jobs, controllers, migrations, framework adapters.
- Eloquent models live in `Domains/{Domain}/Models`.
- Relations stay on models when Eloquent query capabilities are needed.

## Flow Engine Rules

- A handler is resolved by `(type, version)` from the in-memory registry.
- A handler is graph-unaware: it returns a `sourceHandle`, not the next node id.
- A breaking change in a node contract requires a new handler version; existing flow definitions keep working.
- A flow session snapshots its `flow_definition_id` until it completes.
- A handler must be safe to retry; external side effects need an idempotency marker or equivalent protection.
- State keys are namespaced. The canonical list is the enum `Fapost\Foundation\Flow\Enums\StateNamespace`:
  `system`, `flow`, `rag`, `module`, `contact`, `call`. New namespaces go into the enum, not into individual nodes.
- `module.*` is read-only and resolved through `DataAccessorInterface`; `contact.*` and `call.*` are derived
  projections, neither stored in nor written to the session.
- `system.*` writes are allowed only for explicitly whitelisted runtime handlers.
- Do not return legacy `effects[]`; use the writer/port from the execution context.

## Messaging And Queues

Queues are not mixed by purpose:

- `flow.execution` - inbound processing and the execution pipeline.
- `messaging.transactional` - replies in an active dialogue.
- `messaging.broadcast` - low-priority broadcasts / fan-out.
- `messaging.system` - service notifications.
- `scheduled.triggers` - scheduled/event trigger fan-out.
- `sync.external` - external synchronisations.

Provider rate limits and backpressure are preventive, not only a reaction to a
provider error.

## Multilingual Rules

Two language layers are kept apart:

- Admin UI language - Laravel lang files, Filament/backend validation, staff UI.
- Content language - runtime assistant messages to the end user.

Runtime language resolution goes through `LanguageResolverInterface` and the
content translator chain. Do not put user-facing, bot-facing literals directly
into handlers or senders; such strings are system translation keys or flow
content.

A button/select `value` is language-agnostic and never translated; only the
label/content is.

## Frontend Builder Rules

- The builder is driven by the registry/config schema and the existing overrides.
- Use a bespoke override for core node-specific UI only when the schema-driven renderer is insufficient.
- A plugin cannot ship Vue components without a frontend rebuild; extend the schema renderer in Core instead.
- Solution/vendor components are allowed only through the agreed Vite glob/publish contract.
- Do not add marketing landing surfaces to the builder/admin in place of working functionality.

## Documentation Discipline

- `docs/platform/current-state.md` describes fact, not intent.
- `docs/platform/TASKS.md` may use `done / partial / pending` when one line covers both scaffolding and product feature.
- `docs/platform/ROADMAP.md` describes future milestones and dependencies.
- `docs/site/` holds the sources of the published site (Mintlify, docs.fapost.in). Anything described there is
  not duplicated under `docs/`; link to it instead.
- Active source-of-truth documentation is written in English. Archive files may keep their original language
  until deleted or rewritten.
- `drafts/` contains only `CURRENT_TASK.md`.
- `CLAUDE.md` must not claim that tables, models, jobs or UI exist unless that is an architectural rule
  confirmed by the code.
- On a discrepancy between code and documentation, first establish which it is: stale documentation, a partial
  feature, or a false positive in the code.

## Verification

- `composer test` / `php artisan test` runs the PHPUnit suites from `phpunit.xml` (SQLite in memory).
- `composer run test:arch` runs the PHPat architecture rules through PHPStan.
- PHPat rules live in `tests/Architecture`; the low-level command is
  `vendor/bin/phpstan analyse --configuration phpstan.neon`.
- The default PHPUnit run covers PHPat through `tests/Unit/Architecture/MigrationTest.php`, which runs phpstan.
- Do not run `php artisan test tests/Architecture` as the PHPat check: those classes are not PHPUnit `TestCase`s
  and the command reports success while verifying nothing.
- `make` lists the wrapped commands; `CONTAINER` in `.make.local` routes them into a container.
- CI (`.github/workflows/ci.yml`) runs the same checks on every pull request, plus `go test` for the gateway
  and type-check/Vitest/build for the frontend. The PHPUnit suite runs twice there: on SQLite, as locally,
  and on PostgreSQL 15, where schema switching, partitions and `uuid` columns are exercised for real.
  How to run it on PostgreSQL locally: https://docs.fapost.in/contributing/testing

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application running on PHP 8.4. You are an expert with the Laravel ecosystem. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists (settled decisions, non-obvious traps, standing constraints). Framework and package guidelines that only apply to specific paths (testing, frontend, components) also live there, under `.ai/rules/boost` — this is not just recorded decisions, it is load-bearing guidance you have not seen inline. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.
- Record durable rules with `record-rule` so the next agent or teammate inherits them instead of working them out again. Pass a `glob` (e.g. `app/Http/Controllers/**`), a short `title`, and a few-line `note`. Always use `record-rule`, never your native memory or notes tool — native memory is personal and session-scoped; only `.ai/rules` is shared with the team and persists in the repo.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Test every code change by adding or updating a test.
- Run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.

- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
