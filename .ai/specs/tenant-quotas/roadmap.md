# Roadmap — Tenant quotas (Core side)

Destination: one Core deployment serves many tenants by host, refuses any work a tenant's limits
forbid through a Foundation contract that the SaaS shell implements, can stop a tenant's runtime
while its panel stays readable, and never lets a flow reach the private network — so the shell
can put Core online for testers.

## Phase 1 — Safe to put online

Goal: Core can host several untrusted tenants on one deployment. Done when: two tenants are served
from their own hosts on one deployment, and a `call` node aimed at a private, loopback or metadata
address fails in both.

- [ ] Egress guard — the `call` node refuses private, loopback, link-local and metadata addresses after DNS resolution and on every redirect, for every install
- [ ] Host-based tenant resolution — any active tenant is resolved from `<slug>.<base_domain>`, panels and builder answer on every tenant host with a per-host session; the single-slug mode stays for self-hosted installs

## Phase 2 — Quota seams

Goal: every path that creates a countable record or spends volume asks the quota contract first.
Done when: with a test implementation that denies everything, no assistant, flow, channel, staff
user or contact can be created, no new contact starts a flow, and no outbound message or `call`
leaves — while the default implementation changes nothing.

- [ ] Quota contract — a Foundation contract (check a count limit, consume a volume unit, tell why a request was refused) with a Core default that allows everything; `TenantSettings::$max_contacts` removed
- [ ] Count limits on creation — assistants, flows, channels, staff and the other countable models refuse creation when the contract denies it, the Create button is hidden with a reason, and an architecture test keeps every create path inside its service (after: Quota contract — it is what they call)
- [ ] Inbound active-contact gate — an inbound message the contract refuses creates no contact and starts no flow, is logged, and the admin panel shows the refusal (after: Quota contract — it is what the gate calls)
- [ ] Outbound volume gate — outbound messages, broadcast sends and `call` executions consume volume through the contract and stop when refused (after: Quota contract — it is what the gate calls)

## Phase 3 — Operator surface

Goal: the operator package can manage a tenant's life without depending on Core. Done when: a
package that depends only on Foundation provisions a tenant, stops it and resumes it.

- [ ] Landlord ownership ADR — amends "landlord data is reached only through Tenancy" to name the operator package as the other owner of its own landlord tables
- [ ] Operator contracts — Foundation contracts to provision a tenant and to switch its access mode, implemented by Core over `TenantProvisioningService`
- [ ] Runtime-stopped, read-only tenant — in that mode inbound is not processed, delay wake-ups and broadcasts do not run, and the admin panel is read-only with a banner the operator package fills (after: Operator contracts — the mode is switched through them)
- [ ] fog: read-only depth in the builder — whether the builder opens without save or is closed, cannot be stated until the read-only panel exists

## Waves

1. Egress guard; Host-based tenant resolution; Quota contract; Landlord ownership ADR; Operator contracts
2. Count limits on creation; Inbound active-contact gate; Outbound volume gate; Runtime-stopped, read-only tenant

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
