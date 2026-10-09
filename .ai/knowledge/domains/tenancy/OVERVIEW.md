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
  - "tests/Feature/Domains/Tenancy/**"
  - tests/Unit/Architecture/WebhookArchitectureTest.php
  - app/Http/Middleware/ResolveTenantContext.php
reviewed_at: 2026-10-06
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

- Operator provisioning contract: `Fapost\Foundation\Tenancy\Contracts\TenantProvisionerInterface` (public, in Foundation) is implemented by `Infrastructure/CoreTenantProvisioner`, which wraps `Services/TenantProvisioningService` and maps its failures to `ProvisioningFailure` reasons; a taken slug is checked through the repository before the service runs. It takes a password hash, never a plain password. Synchronous and slow, so callers run it outside HTTP requests; internally it is `reserveSlug` (no key) plus `provisionReserved`, so after `Failed` the slug stays taken by a Pending row whose id the exception carries.
- Reservation contract: `Fapost\Foundation\Tenancy\Contracts\TenantReservationInterface` (`check`, `reserve(slug, reservationKey)`, `release`, `provision(tenantId, TenantAdmin)`) is implemented by `Infrastructure/CoreTenantReservations` over the same service. `Failed` and `InProgress` are retryable by id; `Conflict` (a schema without this row's claim, or a foreign user in the schema) needs an operator.
- Operator directory contract: `Fapost\Foundation\Tenancy\Contracts\TenantDirectoryInterface` (public, read-only, in Foundation) is implemented by `Infrastructure/CoreTenantDirectory`: a paginated, searchable list of tenants plus `find`/`findMany`, exposing only id, slug, status, creation time and the tenant's URLs (never the schema name or `config`). This is how a package is given tenants to read; it takes ids as lowercase RFC 4122 ULIDs and treats any other form as naming no tenant.
- Bindings: `app/Providers/DomainServiceProvider.php`. Tenant context, database manager,
  `CoreBootstrap` and `TenantSwitcher` are `scoped`; repository and resolver are `bind`; the
  webhook registry reader and writer are `singleton`. The provider also installs the custom
  pgsql connection resolver that makes schema switching possible.
- HTTP: `tenancy.resolution` picks the mode. `single` (default): `Services/ConfigTenantResolver`
  serves the one tenant named by `tenancy.default_tenant_slug`. `host`:
  `Services/RequestHostClassifier` sorts the request host into platform (the base domain or a
  declared `tenancy.platform_subdomains` label, no tenant), tenant (`<slug>.<base_domain>`,
  resolved by `Services/HostTenantResolver`) or foreign (404).
  `app/Http/Middleware/ResolveTenantContext.php` is prepended to `web`;
  `app/Http/Middleware/TenancyMiddleware.php` (alias `tenant`, in the Filament panel stacks and on
  builder/media/tma) requires a tenant and is placed before the session stack by the middleware
  priority list. Both enter the tenant through `Services/TenantRequestRunner`. Filament panels are
  bound to the default tenant's host in `single` mode and to no domain in `host` mode
  (`Support/TenantHost::panelDomain()`).
- Workers: `TenantSwitcher::runForTenant()`.
- Lifecycle: `Services/TenantProvisioningService` creates a tenant; `Services/TenantDecommissioner`
  removes one (webhook registry entries, then schema, then landlord row) — irreversible, used only
  by the load-test harness for the throwaway tenants it provisioned.
- Console: `app/Console/Commands/Ops/Tenants*Command.php`, `WebhookRegistryHealthCommand`,
  `app/Console/Commands/Platform/Install*Command.php`.
- Schema: `database/migrations/landlord/` is platform-wide; `database/migrations/tenant/` runs
  once per tenant schema.
