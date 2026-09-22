---
id: domain-tenancy
type: domain
status: active
summary: Tenant context, schema switching, landlord data, provisioning and the webhook registry
domains:
  - tenancy
topics: []
load: domain
paths:
  - "app/Domains/Tenancy/**"
  - app/Providers/DomainServiceProvider.php
  - app/Http/Middleware/TenancyMiddleware.php
  - "database/migrations/landlord/**"
  - config/tenancy.php
  - "tests/Unit/Domains/Tenancy/**"
  - "tests/Feature/Tenancy/**"
  - "app/Console/Commands/Ops/Tenants*"
reviewed_at: 2026-09-22
---
# Tenancy

## Responsibility

Tenancy owns the tenant context: which tenant the current request or job runs for, and the
switch of the PostgreSQL `search_path` to that tenant's schema. It owns the landlord data —
the `tenants` table and the `webhook_registry` table on the `landlord` connection — provisions
new tenants (schema, migrations, first admin), and keeps the Redis webhook cache consistent
with the landlord table.

Not to be confused with the *assistant panel's* Filament "tenant", which is an `Assistant`
(see domain `assistant`).

## Boundaries

- Every other domain depends on Tenancy. The intended surface is `app/Domains/Tenancy/Contracts`
  (`TenantContextInterface`, `TenantRepositoryInterface`, `WebhookRegistryReaderInterface`,
  `WebhookRegistryWriterInterface`, …). Two concrete classes are also imported widely and act
  as de-facto API: `Services/TenantSwitcher` and `Settings/TenantSettings`.
- Tenancy depends outward only through `Services/TenantProvisioningService`, which uses Staff
  (`User`, `UserStatus`, `AclBootstrapService`) and the Channels contract
  `ChannelWebhookRegistryInterface`. That single class creates the Tenancy↔Staff and
  Tenancy↔Channels import cycles.
- Webhook ingress does not touch Tenancy in the HTTP controller; the tenant is switched inside
  the queued job (`tests/Unit/Architecture/WebhookArchitectureTest.php`).

## Entry points

- Bindings: `app/Providers/DomainServiceProvider.php`. Tenant context, database manager,
  `CoreBootstrap` and `TenantSwitcher` are `scoped`; repository and resolver are `bind`; the
  webhook registry reader and writer are `singleton`. The provider also installs the custom
  pgsql connection resolver that makes schema switching possible.
- HTTP: `app/Http/Middleware/TenancyMiddleware.php` (prepended to `web`, and listed in the
  Filament panel stacks). The tenant comes from `Services/ConfigTenantResolver`, which resolves
  `tenancy.default_tenant_slug` — not the request host.
- Workers: `TenantSwitcher::runForTenant()`.
- Lifecycle: `Services/TenantProvisioningService` creates a tenant; `Services/TenantDecommissioner`
  removes one (webhook registry entries, then schema, then landlord row) — irreversible, used only
  by the load-test harness for the throwaway tenants it provisioned.
- Console: `app/Console/Commands/Ops/Tenants*Command.php`, `WebhookRegistryHealthCommand`,
  `app/Console/Commands/Platform/Install*Command.php`.
- Schema: `database/migrations/landlord/` is platform-wide; `database/migrations/tenant/` runs
  once per tenant schema.
