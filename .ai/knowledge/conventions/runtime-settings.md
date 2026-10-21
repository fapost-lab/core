---
id: convention-runtime-settings
type: convention
status: active
domains: []
paths:
  - "config/**"
  - "database/settings/**"
  - .env.example
  - "app/Domains/*/Settings/**"
  - "app/Domains/Tenancy/Services/PlatformSetting*.php"
  - app/Domains/Tenancy/ValueObjects/PlatformSettingField.php
summary: A setting an operator or tenant admin may change at runtime is stored in the database and edited in the admin of its scope; .env keeps only secrets, infrastructure and once-per-install values.
reviewed_at: 2026-10-21
---
# Runtime settings live in the database, not in `.env`

## Practice

Before adding an `env()` call, or a key to `config/*.php` that a person would tune, ask: may an
operator or a tenant admin want to change this while the platform runs? If yes, it is a stored
setting, not an environment variable (`adr-20261016-runtime-settings-in-database`):

- a tenant's setting goes into the tenant's settings (Core: `TenantSettings`, edited on the tenant
  admin's settings screen; a Solution: its own settings in the tenant admin);
- a platform-wide setting of Core goes into Core's platform settings store: a `PlatformSettingField`
  registered in `PlatformSettingRegistry` from the owning domain's provider `boot()` (a domain PHPat keeps off
  Tenancy's internals, such as Solution, registers it from `App\Providers\DomainServiceProvider` instead; its default names a
  config key, so the `.env` variable keeps working as the default), read through
  `PlatformSettingsReaderInterface` (Tenancy contract, scoped). The landlord tables, the admin screen
  (single mode only), the `platform:settings` commands and Foundation's `PlatformSettingsInterface` (for an
  operator panel) all render from that registry; nothing else lists the fields;
- a package's setting goes into the package's own store and admin screen (the SaaS package:
  `saas_settings` behind Platform settings), never into Core's.

`.env` keeps only secrets and credentials; Laravel and infrastructure settings (connections,
drivers, cache, queue, session, mail transport and sender, logging, filesystems, proxies, network
egress, engineering tuning such as lock TTLs and retries); values set once per installation (tenancy
resolution, base domain, `SAAS_HOST`, prefixes); and values needed before the database is reachable.

A stored setting has a typed default in code, is read through one service that caches it per request
or job (bound `scoped`, never a singleton or a static), and is validated where the admin writes it.
An `.env` value may serve only as the default a fresh installation starts with; the stored value
wins. A setting that guards access fails closed when it cannot be read.

## Example

The SaaS package's renewal link: `SAAS_RENEWAL_URL` seeds the default, `PlatformSettings` reads
`saas_settings` through `SettingsCache` and answers the stored value when an Owner set one in
Platform settings. A tenant's setting on a worker path: the message sender reads
`TenantSettings::messaging_rate_limit` at send time through the scoped `TenantMessagingRateLimit`,
memoized per tenant id and read from a fresh `TenantSettings` (a tenant switch hides the scoped
instance for its length and restores it on exit, so the memo cannot lean on that instance serving the
right tenant); `MESSAGING_RATE_LIMIT_PER_MINUTE` is only a fallback outside a tenant
or when the tenant's row is absent — every tenant stores 30 at provisioning, because a settings
migration may not read config to seed from `.env`. Before that fix the sender read only `.env` and the field
on the settings screen did nothing — two places, and the visible one ignored.

## Rationale

The person who wants a different value is an operator or an admin, not whoever can redeploy. A
setting in the admin changes without a deploy and has one place to look. The cost — a cached read,
a migration, a field and a test per setting — is paid once; an `env()` knob costs every operator a
support request.
