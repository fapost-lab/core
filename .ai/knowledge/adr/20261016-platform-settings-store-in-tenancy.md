---
id: adr-20261016-platform-settings-store-in-tenancy
type: adr
status: accepted
date: 2026-10-16
domains: []
paths:
  - "app/Domains/Tenancy/Services/PlatformSetting*.php"
  - app/Http/Controllers/Admin/PlatformSettingsController.php
  - "app/Console/Commands/Platform/*PlatformSetting*.php"
  - "database/migrations/landlord/*platform_setting*"
reviewed_at: 2026-10-16
summary: "Core's platform-wide settings: landlord tables owned by Tenancy, a field registry, a scoped once-per-job reader; single-mode admin screen, platform:settings commands and Foundation's PlatformSettingsInterface for an operator panel"
---
# Core's platform-wide settings live in a landlord store owned by Tenancy, edited by the single tenant's admin, by command, or by an operator panel through Foundation

## Context

`adr-20261016-runtime-settings-in-database` asked for a store of Core's platform-wide settings and an
admin to edit them; the first two are the flow step cap (`FLOW_MAX_ITERATIONS`) and the rename redirect
window (`TENANCY_RENAME_REDIRECT_DAYS`). Forces: only Tenancy may open `landlord`, and among packages only
the SaaS operator package owns landlord tables; Core has no platform-level user (roles live in tenant
schemas); in `host` mode the base domain serves no console; the SaaS package must show the same fields
without reading Core's tables; reads sit on the flow engine's hot path in long-lived workers. Decided with
the owner at the gate of task `core-platform-settings` (2026-10-16).

## Decision

- **Store:** landlord tables `platform_settings` (ULID id, unique key, json value; no row means the default)
  and `platform_setting_events` (every change with its actor), owned by Tenancy and written only by
  `App\Domains\Tenancy\Services\PlatformSettings`.
- **Fields:** a `PlatformSettingField` per key in `PlatformSettingRegistry`, registered by the owning
  domain's provider in `boot()`; its default names a config key, so the `.env` variable stays the default.
  The screen, the commands and the Foundation contract all render from the registry.
- **Reads:** `PlatformSettings` is bound `scoped` and reads the table once per request or job; there is no
  cross-process cache, so nothing needs invalidating and a change applies from the next request or job.
  An unreadable table answers the defaults.
- **Who edits:** in `single` mode the tenant's `admin` role on `admin/platform-settings` (settings that
  only matter in host mode are hidden); in `host` mode there is no Core screen, only
  `platform:settings[:set|:reset]` and an operator package through Foundation's experimental
  `Settings\Contracts\PlatformSettingsInterface` (Foundation 0.24). Core decides by mode, never by
  whether a package is installed.
- **Scope:** Core domains only. Packages and Solutions do not register platform settings here.

## Alternatives

- **spatie/laravel-settings on `landlord`:** its migrations would need a landlord path next to the one
  `config/settings.php` keeps empty on purpose (`adr-20261005-extension-packages-own-landlord-tables`), the
  name `settings` is already left over in some landlord schemas, its cache is not versioned across
  processes, and a fixed settings class still needs a registry for a schema-driven screen.
- **A "platform admin" flag on a user of a designated tenant:** a tenant admin would change every other
  tenant in `host` mode; a new privilege to protect, and control-plane logic in Core.
- **A platform console of Core's own on the base domain:** its own sign-in and accounts duplicate the
  operator panel; Core is not the control plane.
- **A cross-process cache under a version token (as the SaaS package does):** saves one small query per
  job; Core's default cache store is the database on the default connection, which after a tenant switch
  is the tenant's schema. Left for later, if measured.
- **Open registration for packages and Solutions:** contradicts "a package keeps its settings in its own
  store" and the landlord rule; needs its own decision when a package asks.

## Consequences

- An operator changes the step cap and the redirect window without a deploy; existing installs behave as
  before until someone stores a value.
- Every job that reads a setting pays one indexed landlord query.
- A new platform setting costs a field, lang keys `console.platform_settings.fields.<key>` in en/ru/uk, and
  a test; the screen, CLI and operator panel pick it up.
- The Foundation contract is experimental; changing it needs a Foundation minor and the SaaS panel's update.
