---
id: adr-20261023-onboarding-state-and-gate
type: adr
status: accepted
date: 2026-10-23
domains:
  - onboarding
  - tenancy
paths:
  - "app/Domains/Onboarding/**"
  - "app/Infrastructure/Onboarding/**"
  - app/Http/Middleware/EnsureTenantOnboarded.php
  - database/settings/2026_10_23_100000_add_onboarding_settings.php
summary: Onboarding state is pending only by provisioning, written only by the wizard, and the gate fails open
reviewed_at: 2026-10-23
---
# A new tenant's onboarding is pending only when provisioning says so, the wizard is its only writer, and the gate fails open

## Context

A new tenant's first administrator must confirm the content languages and create a first assistant before the
console opens (spec `tenant-onboarding`, D1–D3): the tenant's base language locks with the first published flow,
an assistant's language with its first flow. The gate that holds the console runs on every console, builder and
media page, for every tenant, and the feature ships to installations whose tenants have worked for months. Three
things had to be decided: how a tenant that existed before the release is never held, who writes the state, and
what the gate does when it cannot read it. The spec's first answer (D7: an installation-wide "switch-on" moment in
landlord storage compared with the tenant's creation time) met two facts: `TenantInterface` exposes no creation time
(`RuntimeTenant` has none at all), and `PlatformSettings` is a registry of fields an operator tunes, not a place for
internal state. The owner also wanted to restart the wizard for any tenant.

## Decision

- The state is tenant data in its own settings class, `OnboardingSettings` (group `onboarding`): `state`
  (`pending` | `complete`), `confirmed_steps`, `started_at`, `completed_at`. The settings migration that adds it
  writes `complete`; it runs for every existing tenant on the upgrade, so none is held. Provisioning writes
  `pending` after its `first_admin` step, through Tenancy's `NewTenantInitializerInterface` (implemented in
  `Infrastructure/Onboarding`): a freshly provisioned tenant is the only one that starts pending. A seeder or import
  that creates a populated tenant marks it `complete` itself (`DemoContentSeeder`, `LoadTestSeedCommand`).
- The wizard is the only writer. After each step it recounts progress and writes `complete` when every required
  step is done and no check threw. The gate only reads.
- The gate fails open. Settings that cannot be read (a missing row, a query error) count as not pending, and a
  step whose check throws counts as done for that request but never lets the wizard write `complete`.
- A restart (`tenants:onboarding:restart`, Foundation's `TenantOnboardingInterface` for operator packages) writes
  `pending` again and forgets the confirmations; steps done by the data stay done.

## Alternatives

- **An installation-wide switch-on moment compared with the tenant's creation time** (the spec's D7). Rejected: it
  needs a new landlord record and the creation time is not reachable through `TenantInterface`, and a restart for an
  old tenant becomes an exception to the rule.
- **The state inside `TenantSettings`.** Rejected: the tenant settings editor saves the class whole, so the
  settings screen would start writing the wizard's internal state.
- **A tenant table for the state.** Rejected: existing tenants would need a backfill, and migrations are pure DDL.
- **The gate settles the state on the first request it sees.** Rejected: the bell's polling XHR, concurrent first
  requests and support sessions race to decide it; with one writer there is nothing to race.
- **Fail closed, as `conventions/runtime-settings.md` asks of a setting that guards access.** Rejected: the gate
  guards a first-run convenience, not access — writes are not gated and the language lock is enforced on the server
  anyway — while failing closed would lock every tenant's console on a half-migrated deploy or a contributor's bug.

## Consequences

- Upgrading never holds a working tenant, and restarting one is the same write provisioning makes.
- A new provisioning path that bypasses `TenantProvisioningService` creates a tenant that is never held: it has to
  call the initializer itself or accept that.
- A required step whose check is wrong lets the tenant in early rather than locking it out; the warning in the log
  is the only signal.
- `OnboardingSettings` is read fresh on every call, never cached in an instance, so it cannot leak across a tenant
  switch; it needs no hook in `DomainServiceProvider`.
