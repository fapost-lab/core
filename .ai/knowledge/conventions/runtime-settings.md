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
summary: A setting an operator or tenant admin may change at runtime is stored in the database and edited in the admin of its scope; .env keeps only secrets, infrastructure and once-per-install values.
reviewed_at: 2026-10-16
---
# Runtime settings live in the database, not in `.env`

## Practice

Before adding an `env()` call, or a key to `config/*.php` that a person would tune, ask: may an
operator or a tenant admin want to change this while the platform runs? If yes, it is a stored
setting, not an environment variable (`adr-20261016-runtime-settings-in-database`):

- a tenant's setting goes into the tenant's settings (Core: `TenantSettings`, edited on the tenant
  admin's settings screen; a Solution: its own settings in the tenant admin);
- a platform-wide setting of Core goes into Core's platform settings store. Core has none yet: file
  the setting against that store instead of adding an `env()` call;
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
Platform settings. Counter-example: `MESSAGING_RATE_LIMIT_PER_MINUTE` is read by the message sender
from `.env`, while the tenant admin edits `TenantSettings::messaging_rate_limit`, which nothing
applies — two places, and the visible one does nothing.

## Rationale

The person who wants a different value is an operator or an admin, not whoever can redeploy. A
setting in the admin changes without a deploy and has one place to look. The cost — a cached read,
a migration, a field and a test per setting — is paid once; an `env()` knob costs every operator a
support request.
