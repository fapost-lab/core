# Rules and Invariants

Normative requirements for this project. Rules say how work must be done; invariants say
what must always hold. Both are enforced in review and verification. Area-specific rules
live in `domains/<domain>/RULES.md`; this file holds only what binds the whole system.

Each entry names its source and what enforces it. "Review only" means nothing fails
automatically when it is broken. The full wording of a constraint lives in the convention or
domain rules named as its source; this file is the index of what holds and how it is checked.

## Invariants

- **Runtime code runs inside an explicit tenant context.** Code that needs a tenant fails fast
  when none is set; there is no fallback to a default tenant and no branching on the deployment
  shape (single- or multi-tenant). Source: `domains/tenancy/RULES.md`, `ARCHITECTURE.md` § System-wide constraints. Enforced:
  `TenantContext::get()` throws when unresolved (`domains/tenancy/RULES.md`); the rest is review only.
- **No job- or tenant-scoped state survives a queue job.** Horizon workers run many jobs per
  process. A singleton must not hold the request, the config repository, the tenant context,
  the current assistant, or anything that transitively holds them; mutable job state lives in
  `scoped` bindings; tenants are switched through `TenantSwitcher::runForTenant()`, restored in
  `finally`; nothing writes static properties between jobs. Source: `conventions/worker-safety.md`, ADR-01 (`docs/platform/architecture/adr/01-octane-ingress-only.md` — cancelled for
  Octane, its worker-safety constraints still stand), ADR-0001.
  Enforced: for the Flow runtime by `tests/Architecture/FlowRuntimeIsolationTest.php` and
  `NodeHandlerScopedDependenciesTest`; elsewhere review only.
- **Migrations are pure DDL.** A migration does not depend on application code, runtime state,
  tenant context, module activation, or another module's tables. Source: `conventions/migration-isolation.md`. Enforced: `tests/Architecture/MigrationTest.php` forbids dependencies on
  `App\Domains` and `App\Services`; helpers such as `config()`, `env()` and `app()` are review only.
- **Tenant-schema models use ULID primary keys in `uuid` columns** through `HasUlidPrimaryKey`,
  with no database default. Source: ADR-03 (`docs/platform/architecture/adr/03-id-strategy-ulid.md`).
  Enforced: `tests/Architecture/IdStrategyTest.php` (models in `App\Domains\*\Models`, with named
  exceptions such as the composite-key `FlowCallgraphEdge`).
- **Extension packages never depend on Core.** `fapost/foundation` requires no FaPost package,
  `fapost/support` requires only `fapost/foundation`, and neither imports `App\…`. Source: `ARCHITECTURE.md` § Boundaries and dependency direction, ADR-05. Enforced: the packages are separate repositories
  with their own Composer requirements, so a Core import cannot autoload there.
- **The Flow domain does not depend on messaging provider implementations.** Source: `conventions/queues.md`. Enforced: `tests/Architecture/MessagingBoundariesTest.php`.

## Rules

- **Landlord data is reached only through Tenancy.** Other domains depend on a contract from
  `Tenancy/Contracts`; `DB::connection('landlord')` stays inside Tenancy infrastructure. Source: `domains/tenancy/RULES.md`,
  `ARCHITECTURE.md` § Boundaries and dependency direction. Review only.
- **Controllers and jobs orchestrate; business logic lives in services and domain classes.**
  Domain services take collaborators through the constructor, never through `app()`,
  `resolve()` or global helpers; facades belong to infrastructure (providers, jobs, controllers,
  migrations, framework adapters). Source: `conventions/domain-code.md`. Review only, except
  the Flow runtime (`FlowRuntimeIsolationTest`).
- **Queues are separated by purpose.** Work goes to the queue named for it in
  `conventions/queues.md`; provider rate limits and backpressure are preventive. Review only.
  Note: `sync.external` is reserved — Horizon listens on it and the published docs describe it,
  but no job dispatches to it yet.
- **The admin UI language and the content language stay apart.** Staff-facing text uses Laravel
  lang files; text sent to contacts is resolved through `LanguageResolverInterface` and the content
  translator chain, never as a literal in a handler or sender; a button or select `value` is never
  translated. Source: `conventions/multilingual.md`, ADR-15. Review only.
- **Published documentation lives in `docs/site/` and is not restated under `docs/`.** Source:
  `conventions/docs-layout.md`, `conventions/published-docs-updates.md`. Review only.
- **Architecture rules run through PHPStan, never as a PHPUnit directory.** A PHPat class runs
  only when registered in `phpstan.neon`, and `php artisan test tests/Architecture` reports
  success while checking nothing. Source: `.ai/rules/architecture.md`. Enforced:
  `tests/Unit/Architecture/RuleRegistrationTest.php`.
