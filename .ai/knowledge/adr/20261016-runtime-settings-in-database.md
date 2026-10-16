---
id: adr-20261016-runtime-settings-in-database
type: adr
status: accepted
date: 2026-10-16
domains: []
paths:
  - "config/**"
  - .env.example
  - "app/Domains/*/Settings/**"
summary: "Owner decision: runtime settings live in the database and are managed from the admin of their scope (tenant, Core platform, package); .env keeps only secrets, infrastructure and once-per-install values."
reviewed_at: 2026-10-16
---
# A setting an operator or admin may change while the platform runs lives in the database and is managed from the admin; `.env` keeps only secrets, infrastructure and once-per-install values

## Context

Configuration grew through `config/*.php` and `.env`: every new knob got an `env()` call because that
is the shortest path. A value in `.env` changes only with a redeploy or a restart by whoever holds the
server, and the person who wants to change it is usually an operator or a tenant admin who holds
neither. The SaaS operator package already showed the other way: its Platform settings screen keeps
operator emails, support access, branding and the renewal, upgrade and add-on links in
`saas_settings`, with `.env` only as the default. Core keeps a tenant's settings in `TenantSettings`
(spatie/laravel-settings, tenant schema) behind the tenant admin's settings screen, but many runtime
knobs (conversation logging and retention, the flow iteration cap, the rename redirect window, the
messaging rate limit) are still `.env` only. The owner decided (2026-10-16) to make the database the
default home of settings across Core and every package.

## Decision

- **A setting that an operator or a tenant admin may want to change while the platform runs lives
  in the database and is managed from an admin screen.** This binds Core, `fapost/foundation`,
  `fapost/support`, the SaaS operator package and every future package and Solution.
- **Where it lives is decided by its scope and its owner:**
  - a tenant's setting: in the tenant schema, edited in the tenant admin (Core: `TenantSettings`
    and its settings screen; a Solution: its own settings, shown in the tenant admin);
  - a platform-wide setting of Core: in Core's platform settings store, edited from Core's
    platform-level admin. Core has no such store yet; until it exists a new platform-wide setting
    is filed against it rather than added to `.env`;
  - a package's setting: in that package's own store and admin, as the SaaS package's Platform
    settings screen does. A package never writes Core's tables or settings to hold its own.
- **`.env` holds only:**
  - secrets and credentials (keys, tokens, passwords, DSNs with credentials);
  - Laravel and infrastructure settings: database, cache, queue, session, mail transport and the
    sending address tied to its DNS, filesystems, logging, broadcasting, the app key and URL,
    drivers and connections, proxies, network egress (allowed private ranges, proxy), and
    engineering tuning of a mechanism (lock TTLs, retries, leases) that no operator decides;
  - settings set once per installation and never changed afterwards: the tenancy resolution mode,
    the base domain, `SAAS_HOST`, schema and Redis prefixes, the storage driver of a domain;
  - a value needed before the database is reachable, or to reach it.
  An installation-wide kill switch may also stay in `.env` when a stored setting must not be able to
  turn the feature back on (the SaaS package's support access: `.env` allows it, the panel decides).
- **How a package exposes its settings:** its own store, read through one service that caches the
  read (one load per request or job, dropped after a write commits; never a singleton or a static),
  with a typed default in code. An `.env` value is allowed only as the default a fresh installation
  starts with; a stored value always wins over it, and resetting the stored value brings the default
  back. Unreadable settings (database or cache down, migrations not applied) answer with the
  defaults, except a setting that guards access, which fails closed.

## Alternatives

- **Keep `.env` as the home of configuration and add admin screens case by case:** rejected by the
  owner. Every case would be argued again, and an operator would have to know which half of the
  settings lives where.
- **A generic key/value table shared by Core and every package:** rejected. It would make packages
  write Core's tables, against the dependency direction (`ARCHITECTURE.md`) and the landlord rule
  (`adr-20261005-extension-packages-own-landlord-tables`), and would lose types and validation.
- **Everything in the database, `.env` reduced to the database connection:** rejected. Secrets do not
  belong in a table an admin screen reads, infrastructure must be set before the application boots,
  and once-per-install values break the installation when changed.

## Consequences

- Operators and tenant admins change behaviour without a redeploy, and there is one place to look
  for a setting: the admin of the scope that owns it.
- A settings read is a database or cache read on a hot path; it must be cached per request or job,
  and the cache must respect worker safety (`conventions/worker-safety.md`): no singleton, no static.
- Every moved setting needs a migration of its default, validation, an admin field and a test; a
  setting is more expensive to add than an `env()` call, on purpose.
- A setting needed before the database is reachable cannot move and stays in `.env`.
- Core needs a platform-wide settings store and a platform-level admin screen; the dead
  `platform.*` group in `database/settings/` is not that store (it is seeded into every tenant
  schema and read by nothing).
- Existing `env()` settings that fail these criteria are moved by follow-up tasks; until each one
  lands, its `.env` variable keeps working as the default.
