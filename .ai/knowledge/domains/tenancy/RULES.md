---
id: rule-tenancy
type: rule
status: active
summary: Fail-fast tenant context, always-restoring switches, landlord access only inside Tenancy
domains:
  - tenancy
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Tenancy/**"
  - app/Providers/DomainServiceProvider.php
  - app/Http/Middleware/TenancyMiddleware.php
  - "database/migrations/landlord/**"
  - config/tenancy.php
  - "tests/Unit/Domains/Tenancy/**"
  - "tests/Feature/Tenancy/**"
  - "tests/Feature/Domains/Tenancy/**"
  - tests/Unit/Architecture/WebhookArchitectureTest.php
  - app/Http/Middleware/ResolveTenantContext.php
  - app/Http/Middleware/RefuseWritesWhenTenantStopped.php
  - tests/Unit/Architecture/RuntimeJobAccessModeTest.php
  - app/Filament/Support/AccessNoticeBanner.php
  - app/Http/Middleware/OnlyOnPlatformHosts.php
  - config/trustedproxy.php
  - config/horizon.php
  - config/telescope.php
  - app/Providers/HorizonServiceProvider.php
  - docker/compose.yaml
  - "tests/Feature/Http/**"
reviewed_at: 2026-10-09
---
# Tenancy rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **A missing tenant context fails fast.** `TenantContext::get()` throws
  `TenantNotResolvedException`; there is no default-tenant fallback in the context itself.
  Enforced: code, `TenantContextTest`.
- **The tenant is resolved before the session starts.** Session, password-reset and activation
  tables live in tenant schemas, so a request must be in its tenant before `StartSession`:
  `ResolveTenantContext` is first in `web`, and `TenancyMiddleware` is prepended to the
  middleware priority list before `EncryptCookies`. Why: with the database session driver a
  session read outside the tenant fails or reads another schema. Enforced:
  `TenantMiddlewareOrderTest`.
- **In `host` mode only `<slug>.<base_domain>` reaches a tenant.** The base domain runs with no
  tenant, and neither does a declared platform subdomain (`tenancy.platform_subdomains`, filled by
  an operator package): it runs with no tenant like the base domain, its tenant panels answer 404,
  and its label is reserved as a slug. Any other host, an unknown, inactive or reserved slug is a
  404, and the default-slug exemption from the reserved list applies only in `single` mode. One
  exception: a tenant's former slug (`tenant_slug_aliases`) answers GET/HEAD with a 302 to the new
  host while `redirect_until` is ahead and the tenant is Active (`TenantSlugMovedException`,
  rendered before the generic not-found; it is deliberately not a `TenantNotFoundException`
  subclass because Pint's `final_class`); other methods and expired aliases are 404.
  Enforced: `RequestHostClassifierTest`, `HostTenantResolverTest`, `HostResolutionTest`,
  `HostModePanelsTest::test_platform_subdomain_runs_without_a_tenant_and_serves_no_panels`,
  `TenantSlugPolicyTest::test_platform_subdomains_are_reserved`.
- **In `host` mode a panel's only host boundary is `TenancyMiddleware`.** Panels are registered
  without a domain (`TenantHost::panelDomain()` is `null`), so `/admin` and `/assistant` match on
  every host; `TenancyMiddleware` first in each panel's middleware — and persistent, so Livewire
  updates replay it — is what turns the base domain and foreign hosts into 404. Never remove it or
  move it after the session stack. URLs built inside a request follow the current host; URLs built
  outside one (mail, queued jobs) must name the tenant host explicitly — through
  `TenantHost::urlFor()`, as the staff activation mail does. Enforced: `HostModePanelsTest`,
  `TenantMiddlewareOrderTest` (every `filament.*` route), `ActivationHostModeTest`.
- **`host` mode needs a session that works with no tenant, and host-only cookies.** Platform pages run
  `StartSession` with no tenant, so `SESSION_DRIVER=database` (a tenant-schema table) is refused, and a
  `SESSION_DOMAIN` that spans subdomains would hand one tenant's cookie to the others. A web process refuses to
  boot with either (`HostModeDeploymentCheck`, called from `DomainServiceProvider`); console and queue processes
  boot anyway so `config:clear` still works. An ingress host (`WEBHOOK_BASE_URL`, gateway URL) outside the base
  domain only warns (`php artisan about`): TrustHosts would answer it 400. Enforced: `HostModeDeploymentCheckTest`,
  `HostModeBootTest`.
- **Horizon and Telescope are never served on a tenant host.** `OnlyOnPlatformHosts` is first in their route
  middleware (`config/horizon.php`, `config/telescope.php`) and answers 404 on a tenant host in `host` mode. Platform
  hosts carry no tenant sign-in, so `viewHorizon` stays an empty allow-list until an operator package brings its own
  sign-in and redefines the gate. Enforced: `PlatformHostOnlySurfacesTest`.
- **Forwarded headers are believed only from `trustedproxy.proxies` (`TRUSTED_PROXIES`), and never the host.**
  `bootstrap/app.php` trusts `X-Forwarded-For` and `-Proto`; the host and port are not (the host
  selects the tenant, TrustHosts runs before TrustProxies, and no proxy of ours sets a port). Empty trusts nobody; the bundled Compose defaults to its pinned Caddy address, not
  a range. Per-IP limits (sign-up) depend on this. Enforced: `TrustedProxiesTest`.
- **A tenant switch always restores.** `runForTenant()` restores the connection, resets the
  context, runs restore hooks and points the permission registrar back at the outer cache key even
  when the database restore itself throws. A restore without a matching switch throws `ConnectionStackEmptyException`.
  Why: a Horizon worker that keeps the previous tenant's schema serves the next job from the
  wrong tenant. Enforced: `TenantSwitcherTest`, `TenantDatabaseManagerTest`.
- **Each tenant's permissions are cached under a key of its own.** On every switch
  `TenantSwitcher` sets the spatie registrar's `cacheKey` to `<permission.cache.key>.tenant.<id>`
  and drops the collection loaded in memory; on restore it returns to the outer tenant's key, or
  the platform key. The cache store is never flushed on a switch. Why: the registrar is a
  worker-wide singleton and the cache store is shared by every worker, so one global key served
  one tenant's roles and permissions to another's requests. Limits: an entry lives until
  `permission.cache.expiration_time` (24h), so a change made outside Eloquent's events — raw SQL,
  a restored schema under the same tenant id — stays invisible until then; flush that tenant's key.
  `permission:cache-reset` clears only the platform key, not the tenants'. Role writes flush the
  key once more after their transaction commits (`RoleWriterService`), so a load racing the commit
  cannot cache the old grants. Enforced: `TenantSwitcherTest`.
- **Schema names are validated before they reach SQL** (`^[a-z][a-z0-9_]*$`, in
  `TenantDatabaseManager`). Why: the name is interpolated into `SET search_path`.
- **Two slugs never map to one physical schema.** The schema name is derived only by
  `TenantSlugPolicy::schemaNameFor()` (`tenant_` + slug with `-` → `_`); the policy caps a slug at
  56 characters and rejects `--`, and `TenantDatabaseManager::createSchema()` / `schemaExists()`
  refuse any name over PostgreSQL's 63-byte identifier limit. Why: PostgreSQL truncates longer
  identifiers silently and the derivation collapses separator runs, so either would let a new
  tenant's admin land in another tenant's schema. `dropSchema()` deliberately skips the length
  check so a legacy long name can still be removed. `config('tenancy.schema_prefix')` is not read:
  the prefix is fixed in `TenantSlugPolicy::SCHEMA_PREFIX`, and the 56-character cap depends on it.
  Enforced: `TenantSlugPolicyTest`, `TenantDatabaseManagerTest`, `TenantProvisioningServiceTest`.
- **Schema DDL runs on the tenant connection** (`config('tenancy.tenant_connection')`):
  `createSchema()`, `schemaExists()` and `dropSchema()` in `TenantDatabaseManager`. Why: `landlord`
  may point at another database (`LANDLORD_DB_DATABASE`), and a schema created there is invisible
  to tenant migrations and queries. Enforced: `TenantDatabaseManagerTest`.
- **An aborted transaction does not drop the connection to `public`.** `TenantPostgresConnection`
  re-applies `search_path` after a rollback. Enforced: `TenantDatabaseManagerTest`.
- **A tenant stays `pending` until provisioning completes, and provisioning resumes by id.**
  `reserve` inserts a Pending row with no schema (unique `slug`, `schema_name`, `reservation_key`;
  the same key returns the same tenant); `provisionReserved` takes a lease on the row (a conditional
  UPDATE, `tenancy.provisioning.lease_seconds`, default 900, at least 60), and every later write —
  schema claim, lease renewal, failure mark, activation — is conditioned on the lease end it read, so
  a run that lost its lease changes nothing. The schema claim marker is written before
  `CREATE SCHEMA`; a schema without this row's marker is never adopted (`Conflict`). The tenant turns
  `active` only after its first admin and the webhook warm-up. A failure leaves the row Pending with
  `<step>: <exception class>`; messages and bindings (a password hash may be among them) go nowhere
  but the exception chain. `release` is one conditional DELETE, refused once a schema is claimed or a
  lease is live. Pending is never served (host and config resolvers require Active) and console
  iterations skip it. Why: a half-provisioned tenant must not accept traffic, and a killed worker
  must not orphan a slug or a schema. Enforced: `CoreTenantReservationsTest`,
  `ResumableProvisioningTest`, `PostgresProvisioningTest` (pgsql), `CreateFirstAdminIdempotencyTest`.
- **A slug changes only through `TenantRenamerInterface`, and a former slug is its tenant's forever.**
  `schema_name` is set once at provisioning and never derived from the slug again, so a rename is
  one conditional UPDATE of `tenants.slug` plus a `tenant_slug_aliases` row, in one landlord
  transaction. A former slug stays reserved for that tenant permanently (`check()`/`reserve()`
  answer Taken); only its redirect window ends (`TENANCY_RENAME_REDIRECT_DAYS`, default 30). Every
  slug claim — rename and `reserveSlug` — takes the same `pg_advisory_xact_lock` on pgsql, and
  `reserve()` re-reads its reservation key after taking it. Rename is refused for Pending tenants
  and outside host mode. Enforced: `CoreTenantRenamerTest`, `HostResolutionTest`,
  `PostgresRenameTest` (pgsql), `CoreTenantReservationsTest`.
- **`TenantReservationInterface` is Core's alone, bound with a plain `bind`.** Core never calls it;
  only an operator package does. Without a package nothing changes.
- **The landlord table is the source of truth for the webhook registry; Redis is a cache.**
  Anything in Redis can be rebuilt by `WebhookRegistryHealthChecker`.

- **Core's default for an operator-replaceable contract is bound with `bindIf`.** Package service
  providers register before application providers, so a plain `bind` in Core would overwrite the
  operator package's binding. `TenantLimitsInterface` (default `UnlimitedTenantLimits`) is bound this
  way in `DomainServiceProvider`; do the same for any contract an operator package may replace.
  Enforced: `RecordQuotaTest` (a binding made before the provider runs wins).
- **Per-period usage is asked of the operator, only through `PeriodQuota`, and fails open.**
  `PeriodQuota::consume(key, unitKey, occurredAt)` requires a registered `PerPeriod` key, takes the
  tenant from `TenantContext` and calls Foundation's `UsageMeterInterface`; Core never computes the
  period. The operator is idempotent by unit key and period, so callers pass a natural key that a
  retry repeats (inbound: `contact:` + sha256 of tenant, platform and external id; `occurredAt`
  stable where the caller has a stable time, otherwise now). An operator error is reported and the
  unit allowed — deliberately the opposite of `RecordQuota`, because no person is there to retry.
  Without an operator package `UnlimitedUsageMeter` (bound with `bindIf`) allows everything.
  Enforced: `PeriodQuotaTest`.
- **Limits are answered by the operator package and counted by Core.** Records live in the tenant's
  schema, which the package cannot see, so `RecordQuota` (Core's `RecordQuotaInterface`, for the tenant in `TenantContext`) takes the current count from the caller, and
  the service that owns creation is the only place a counted record is created: `AssistantService::create()`
  (`assistants`), `CreateFlowAction` (`flows`, one `FlowDraft` per flow, counted tenant-wide), `ChannelService::create()`
  (`channels`, inactive included), `CreatePendingUserService` and `UserService::activate()` (`staff`: users with
  `is_active`, i.e. every account not deactivated, pending and suspended included, through `User::scopeCountedForLimit()`, the one seam for accounts that must not count, such as
  the platform's support user). `AclBootstrapService::createFirstAdmin()` creates a tenant's first admin without the
  check, deliberately. The assistant panel's `assistant_tenancy` global scope narrows `FlowDraft` and `Channel` to the
  current assistant, so their `countForLimit()` strips it. A key unknown to
  `LimitRegistry` throws `LogicException` rather than meaning "no limit". Existing records stay when a
  limit drops below the count; only creating more is refused. Concurrent creates may exceed a limit
  by the number of parallel requests (accepted: tenant schema and landlord are different
  connections). Enforced: `AssistantLimitTest`, `FlowLimitTest`, `ChannelLimitTest`, `StaffLimitTest`, `RecordQuotaTest`, PHPat
  `CountableModelCreationTest` (`new` of a counted model outside its creators) and
  `Tests\Unit\Architecture\CountableModelCreationTest` (static creates; the countable models are
  listed once in `Tests\Support\CountableModels`).

- **Support access is off unless the flag is on, and a token is single use.** `issue()` throws
  when `tenancy.support_access.enabled` is false or the resolution mode is not `host`, and `POST /support/enter` answers 404 before it
  consumes anything. A token is stored hashed in the landlord `support_access_tokens`, lives 60
  seconds, travels in a POST body (never a URL) and is consumed by one conditional UPDATE that must
  change exactly one row; only `SupportAccessTokenStore` touches the table, and the other domains
  use `SupportAccessRedeemerInterface`. The `SupportAccessInterface` binding is a plain `bind`
  (Core is the only implementation), unlike the `bindIf` defaults above. Enforced:
  `SupportAccessIssueTest`, `SupportAccessTokenStoreTest`, `SupportAccessEntryTest`
  (`adr-20261007-support-access-through-platform-support-user`).

- **A stopped tenant is a mode, never a status, and Core asks the operator on every request and runtime job.**
  `TenantRequestRunner` asks `TenantAccessModeInterface` once per request into the `scoped` `CurrentAccessState`;
  the default is `AlwaysActiveAccessMode`, bound with `bindIf`. While stopped: unsafe methods on the builder,
  media and mini-app routes answer 423 (`RefuseWritesWhenTenantStopped`; the platform support user passes; a
  route with no side effects opts out with `withoutMiddleware`), runtime jobs are dropped or postponed by
  `RespectsTenantAccessMode`, and `flow:sweep-subflow-timeouts` skips the tenant. The tenant stays `Active` so
  console commands see it. A postponed job is re-queued as a delayed copy, never `release()`d (a release spends
  an attempt). Fail open: an operator that throws is reported and counts as active (`TenantAccessStates`). A
  gateway "Class@method" handler must run the job's middleware itself. Every queued job in `app/` is either listed as runtime (and gated) or as keeping running. Enforced:
  `StoppedTenantRequestTest`, `StoppedTenantRuntimeTest`, `RespectsTenantAccessModeTest`,
  `RuntimeJobAccessModeTest` (`adr-20261008-access-mode-is-a-mode-not-a-status`).

## Rules

- **Open the `landlord` connection only inside `app/Domains/Tenancy`.** Other domains depend on
  a `Tenancy/Contracts` interface. Why: tenant isolation stays auditable in one place.
  Review only — no PHPat rule covers it. Known exceptions: the install and
  migrate console commands (`InstallPlatformCommand`, `InstallCommand`, `MigrateSmartCommand`) open
  the connection to run platform migrations (`migrate --database=landlord --force`, no `--path`,
  so every registered migration path runs; `database/settings` is not one of them — the settings
  migrations are tenant-scoped, so `config/settings.php` leaves `migrations_paths` empty and they
  run only through `MigrationScope::settings()`), and `AppServiceProvider` loads
  `database/migrations/landlord`. Only the SaaS operator package may open `landlord`, and only for tables of its
  own prefix; it never writes `tenants` or `webhook_registry`. Plugin and Solution packages have no landlord access
  (`adr-20261005-extension-packages-own-landlord-tables`, amended 2026-10-08).
- **In workers, change tenant only through `TenantSwitcher::runForTenant()`**; never set
  `search_path` or the context directly. Review only.
- **Core is not the control plane.** No SaaS logic, billing or onboarding belongs in Core, and
  self-hosted and SaaS must not produce two different core architectures. Source: the vision note
  `docs/platform/architecture/vision.md` (deleted in 2026-10, reachable only through git history), and `docs/idea-brief.md` §5 (the multi-tenant shell is
  the owner's separate closed product). Review only. *(proposed)*
- **Do not add outward dependencies to Tenancy.** `TenantProvisioningService` is the only class
  allowed to reach into other domains (Staff, Channels), and it is the source of both import
  cycles; new needs go through a contract the other domain implements. *(proposed)*
- **Write `webhook:<hash>` keys only through `WebhookRegistryWriter`.** The class states it is
  the only writer; `Webhook/Services/WebhookRegistryResolver` also writes the key today, and the
  `webhook:` prefix is spelled in three places. *(proposed)*
