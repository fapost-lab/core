---
id: glossary-tenancy
type: glossary
status: active
summary: Landlord, tenant schema, tenant context and switch, webhook registry, CoreBootstrap
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
reviewed_at: 2026-10-05
---
# Tenancy glossary

## Landlord

The shared `public` schema, reached through the `landlord` connection, that holds `tenants` and
`webhook_registry`. Only Tenancy infrastructure opens it.

## Tenant schema

The per-tenant PostgreSQL schema, named `tenant_<slug>` at provisioning. Selected by switching
the connection's `search_path`; every domain model lives here.

## Tenant context

The scoped `TenantContextInterface` holding the current tenant. `get()` throws
`TenantNotResolvedException` when nothing is set. Informal synonyms: current tenant.

## Tenant switch

`TenantSwitcher::runForTenant()`: push the tenant's schema onto the connection stack, set the
context, run the callback, and restore everything in `finally`.

## RuntimeTenant

A tenant value object rebuilt from a job payload without a landlord lookup.

## Tenant slug

The tenant's DNS-safe identifier, validated by `TenantSlugPolicy`: a valid DNS label, not
starting with `xn--`, not a reserved name (reserved names include the ingress hosts and the platform subdomains).

## Webhook registry

The landlord table mapping a webhook public hash to its tenant and channel. Redis
`webhook:<hash>` keys are a rebuildable cache of it, reconciled by `WebhookRegistryHealthChecker`.

## CoreBootstrap

The once-per-tenant boot of core services; remembers which tenant it booted and is reset in
`TenancyMiddleware`'s `finally`.

## MigrationScope

Which set of migration paths runs for a tenant: tenant, features, settings, or module.

## TenantStatus

`active`, `inactive`, `suspended`. A newly provisioned tenant becomes active only after its first
admin exists.
