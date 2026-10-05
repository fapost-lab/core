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
reviewed_at: 2026-10-05
---
# Tenancy rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **A missing tenant context fails fast.** `TenantContext::get()` throws
  `TenantNotResolvedException`; there is no default-tenant fallback in the context itself.
  Enforced: code, `TenantContextTest`.
- **A tenant switch always restores.** `runForTenant()` restores the connection, resets the
  context, runs restore hooks and clears the permission cache even when the database restore
  itself throws. A restore without a matching switch throws `ConnectionStackEmptyException`.
  Why: a Horizon worker that keeps the previous tenant's schema serves the next job from the
  wrong tenant. Enforced: `TenantSwitcherTest`, `TenantDatabaseManagerTest`.
- **Schema names are validated before they reach SQL** (`^[a-z][a-z0-9_]*$`, in
  `TenantDatabaseManager`). Why: the name is interpolated into `SET search_path`.
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
