# Roadmap — Tenant quotas (Core side)

Destination: one Core deployment serves many tenants by host, refuses any work a tenant's limits
forbid through a Foundation contract that the SaaS shell implements, can stop a tenant's runtime
while its panel stays readable, and never lets a flow reach the private network — so the shell
can put Core online for testers.

Order: the shell is designed first, in its own spec in its own repository; after that the shell
and Core are built in parallel, and Core items are filed when the shell needs them. The phases
below are the expected Core work, not a fixed schedule.

## Phase 1 — Walking skeleton

Goal: the shell can create a tester tenant with a plan and Core enforces one limit on it. Done
when: a tenant created by the shell opens on its own host, its first assistant is created and the
second is refused with a reason — while an install without the shell behaves exactly as today.

- [x] `host-tenant-resolution` — Host-based tenant resolution — `TENANCY_RESOLUTION`: `single` (default, one tenant from `TENANT_SLUG`) or `host` (any active tenant from `<slug>.<base_domain>`, the base domain tenant-free, foreign hosts 404) (after: shell design — it fixes how the shell names and creates tenants)
- [x] `tenant-host-panels` — Panels on every tenant host — in `host` mode the Filament panels answer on each tenant host, URLs built in a request follow it (after: Host-based tenant resolution — panels rely on its host classification)
- [x] `tenant-host-activation` — Activation on the tenant host — staff activation links name the tenant host and `/activate` is served there (after: Panels on every tenant host — the post-activation redirect lands in the panel)
- [ ] `multi-tenant-deploy` — Deploy for several tenants — wildcard site and certificate, session and gateway constraints, Horizon/Telescope off tenant hosts (after: Panels on every tenant host — the deploy serves what they expose)
- [x] `operator-provisioning-contract` — Operator provisioning contract — a Foundation contract to provision a tenant, implemented by Core over `TenantProvisioningService` (after: shell design — the shell is its only consumer)
- [ ] `quota-contract-assistant-seam` — Quota contract with the assistant seam — the Foundation quota contract and limit registry (key, label, unit, kind) with an allow-everything Core default, wired into assistant creation and the Create button, plus the architecture rule that keeps assistant creation inside its service; `TenantSettings::$max_contacts` removed (after: shell design — the shell is its only consumer)
- [x] `landlord-ownership-adr` — Landlord ownership ADR — amends "landlord data is reached only through Tenancy" to name the operator package as the other owner of its own landlord tables (after: shell design — it names which tables)
- [x] `tenant-directory-contract` — Tenant directory contract — a read-only Foundation contract that lists tenants and returns one with its slug, status, creation time and host URL, implemented by Core over the tenant repository, so the shell's operator panel sees every tenant, those awaiting an operator included (after: Operator provisioning contract — the same Foundation Tenancy section and the same consumer)

- [ ] `pending-tenant-provisioning` — Pending reservation and resumable provisioning — a `Pending` tenant reserves a slug with no schema; provisioning completes it idempotently by tenant id and resumes after a failure or a killed worker (after: Operator provisioning contract — the shell reserves and provisions through it)

## Phase 2 — Safe to put online

Goal: Core can host untrusted tenants. Done when: a `call` node aimed at a private, loopback or
metadata address fails on every install, and every countable record and volume unit goes through
the quota contract.

- [ ] Egress guard — the `call` node refuses private, loopback, link-local and metadata addresses after DNS resolution and on every redirect, for every install
- [ ] Count limits on the other models — flows, channels, staff and the remaining countable models refuse creation when the contract denies it, with the Create button hidden and the architecture rule extended (after: Quota contract with the assistant seam — they repeat its pattern)
- [ ] Inbound active-contact gate — an inbound message the contract refuses creates no contact and starts no flow, is logged, and the admin panel shows the refusal (after: Quota contract with the assistant seam — the gate calls it)
- [ ] Outbound volume gate — outbound messages, broadcast sends and `call` executions consume volume through the contract and stop when refused (after: Quota contract with the assistant seam — the gate calls it)

## Phase 3 — Tenant lifecycle

Goal: the shell can end a trial without losing the tenant. Done when: the shell switches a tenant
to stopped and back, and while stopped nothing runs and the panel is read-only with the shell's
banner.

- [ ] Runtime-stopped, read-only tenant — Core asks a Foundation contract for a tenant's access mode on every request and job (default: active); in the stopped mode inbound is not processed, queued delay wake-ups and broadcasts do not run, the admin panel is read-only with a banner the shell fills, and tenant console commands still migrate stopped tenants (after: Operator provisioning contract — the mode extends the same operator surface)
- [ ] Solution entitlement seam — Core's Solution activation screen asks the operator contract which installed Solutions a tenant may activate (default: all) and deactivates those it loses (after: Operator provisioning contract — the same operator surface; needs `solution-activation-lifecycle`)
- [ ] `support-access-contract` — Support access contract — a Foundation contract through which the shell lets an operator enter a tenant's panel: a one-time, short-lived token bound to the operator opens a session of the tenant's platform support user (admin rights, created on first entry, not removable by the tenant, shown to the tenant in its audit and user list); off by default, so a self-hosted install has no such entry (decided with the owner on 2026-10-06; after: Operator provisioning contract — the same operator surface)
- [ ] `tenant-rename-contract` — Tenant rename contract — a Foundation contract to change a tenant's slug (its host) with the provisioning checks; the schema name stays, and whether the old host redirects is decided in its design (after: Tenant directory contract — the same Foundation Tenancy section)
- [ ] fog: read-only depth in the builder — whether the builder opens without save or is closed, cannot be stated until the read-only panel exists

## Waves

1. Host-based tenant resolution; Operator provisioning contract; Quota contract with the assistant seam; Landlord ownership ADR; Tenant directory contract
2. Panels on every tenant host; Activation on the tenant host; Deploy for several tenants; Pending reservation and resumable provisioning; Egress guard; Count limits on the other models; Inbound active-contact gate; Outbound volume gate; Runtime-stopped, read-only tenant; Solution entitlement seam; Support access contract; Tenant rename contract

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
- A wave entry names an item by its title (the text before its first ` — `) or its task id,
  entries separated by `;` — `jig spec plan` reports an entry that names no item or several.
-->
