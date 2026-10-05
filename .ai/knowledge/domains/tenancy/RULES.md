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
reviewed_at: 2026-10-05
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
  tenant; any other host, an unknown, inactive or reserved slug is a 404, and the default-slug
  exemption from the reserved list applies only in `single` mode. Enforced:
  `RequestHostClassifierTest`, `HostTenantResolverTest`, `HostResolutionTest`.
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
- **An aborted transaction does not drop the connection to `public`.** `TenantPostgresConnection`
  re-applies `search_path` after a rollback. Enforced: `TenantDatabaseManagerTest`.
- **Provisioning leaves a tenant inactive until its first admin exists**
  (`TenantProvisioningService`). Why: a half-provisioned tenant must not accept traffic.
- **The landlord table is the source of truth for the webhook registry; Redis is a cache.**
  Anything in Redis can be rebuilt by `WebhookRegistryHealthChecker`.

## Rules

- **Open the `landlord` connection only inside `app/Domains/Tenancy`.** Other domains depend on
  a `Tenancy/Contracts` interface. Why: tenant isolation stays auditable in one place.
  Review only — no PHPat rule covers it. Known exceptions: the install and
  migrate console commands (`InstallPlatformCommand`, `InstallCommand`, `MigrateSmartCommand`) open
  the connection to run landlord migrations, and `AppServiceProvider` loads
  `database/migrations/landlord`.
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
