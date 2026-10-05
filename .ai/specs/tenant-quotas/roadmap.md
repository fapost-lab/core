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

- [ ] Host-based tenant resolution — any active tenant is resolved from `<slug>.<base_domain>`, panels and builder answer on every tenant host with a per-host session; host is the default, the env-bound single slug is an explicit self-hosted mode only (after: shell design — it fixes how the shell names and creates tenants)
- [ ] Operator provisioning contract — a Foundation contract to provision a tenant, implemented by Core over `TenantProvisioningService` (after: shell design — the shell is its only consumer)
- [ ] Quota contract with the assistant seam — the Foundation quota contract and limit registry (key, label, unit, kind) with an allow-everything Core default, wired into assistant creation and the Create button, plus the architecture rule that keeps assistant creation inside its service; `TenantSettings::$max_contacts` removed (after: shell design — the shell is its only consumer)
- [ ] Platform route context — the base domain serves pages, Livewire requests included, with no tenant context, and guest redirects go to the panel of the host they came from (after: Host-based tenant resolution — the base domain is told apart from tenant hosts by it)
- [ ] Landlord ownership ADR — amends "landlord data is reached only through Tenancy" to name the operator package as the other owner of its own landlord tables (after: shell design — it names which tables)

- [ ] Pending reservation and resumable provisioning — a `Pending` tenant reserves a slug with no schema; provisioning completes it idempotently by tenant id and resumes after a failure or a killed worker (after: Operator provisioning contract — the shell reserves and provisions through it)

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
- [ ] fog: read-only depth in the builder — whether the builder opens without save or is closed, cannot be stated until the read-only panel exists

## Waves

1. Host-based tenant resolution; Operator provisioning contract; Quota contract with the assistant seam; Landlord ownership ADR
2. Platform route context; Pending reservation and resumable provisioning; Egress guard; Count limits on the other models; Inbound active-contact gate; Outbound volume gate; Runtime-stopped, read-only tenant; Solution entitlement seam

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
