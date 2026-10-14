---
id: adr-20261014-solution-activation-in-tenant-schema
type: adr
status: accepted
date: 2026-10-14
domains:
  - solution
paths:
  - "app/Domains/Solution/**"
summary: A tenant's Solution activation is stored in its own schema (state row plus append-only journal), read only through the scoped, tenant-keyed ActiveSolutions as stored-active intersected with installed
---
# A tenant's Solution activation is tenant data, read per scope and keyed by tenant id

## Context

The solution-activation-lifecycle spec makes activation per tenant and calls its storage irreversible:
once a release writes rows in every tenant schema, moving them is a data migration across all
schemas. It must survive a worker restart, never leak between tenants served by one long-lived worker
(`conventions/worker-safety.md`), fit the later entitlement seam (a Solution the tenant may no longer
have is switched off without losing its data, `adr-20261010-solutions-sold-as-add-ons` in the SaaS
repository), and leave a trail of who changed what. Solutions are packages in the image, so a row may
outlive its package.

## Decision

- Activation lives in the tenant's own schema, in two tables created by one tenant migration:
  `solution_activations` (current state, one row per Solution, unique `solution_id`, `status` as a
  string so a later state needs no DDL, `activated_at`, `deactivated_at`) and `solution_activation_events`
  (append-only journal: action, actor kind, actor id without a foreign key, actor label, reason,
  `occurred_at`). Primary keys are ULIDs. Deactivation updates the row and never deletes it, and never
  touches a Solution's own data. No row means never activated, which reads as inactive. Nothing is
  active by default, for new and existing tenants.
- The effective state is answered only by `ActiveSolutions`, bound `scoped`; raw rows and the journal are read
  only through the store port (`all()`, `events()`), by the list command and later screens. It memoises the tenant's stored active ids per
  tenant id, with one lazy indexed query, and answers `isActive` and `ids` as stored-active
  INTERSECT installed-with-a-valid-manifest (`InstalledSolutions`). A removed package or a broken
  manifest therefore makes a Solution inactive by computation, with no write, and bringing it back
  reactivates it where the tenant left it on. There is no cross-process cache. Reading never writes.
- The only writer is `SolutionActivator` (`activate`, `deactivate`, both idempotent, each taking an
  `ActivationActor`). Row and journal entry are written in one transaction holding a lock on the row; the
  first activation inserts with `insertOrIgnore` and reads back under the lock, so concurrent activations
  journal once. Activating a Solution that is not installed with a valid manifest is refused;
  deactivating one is allowed so a stale row can be put out. After a write it drops its tenant's memo.
- The port is `SolutionActivationStoreInterface`; the Eloquent implementation lives in
  `Solution/Infrastructure`. The Solution domain may depend on `App\Domains\Tenancy\Contracts` and on no
  other domain (`SolutionBoundariesTest`); it still does not touch the database outside `Infrastructure`.
- `solutions:activate`, `solutions:deactivate` and `solutions:list` take a required `--tenant=<slug>` and
  work inside `TenantSwitcher::runForTenant()`; the actor is `console`. The `onActivate` and
  `onDeactivate` hooks are not called by this storage.
- The Solution ids `solution` and `solutions` are reserved in Foundation (from 0.14; until Core requires it the parser still accepts them, and no Solution may use them), so no Solution's own
  table prefix can collide with these table names. This extends the reserved list in
  `adr-20261010-solution-manifest-is-data-in-composer-json`.
- Phase 2 (the registrar) is expected to register the handlers of every installed valid Solution once per
  process and to check `ActiveSolutions::isActive` for the action's `<id>.` prefix at call time, so the
  handler registry stays tenant-free. This storage does not depend on that choice.

## Alternatives

- A landlord table keyed by tenant id: convenient for cross-tenant queries, but the Solution domain would
  need landlord access (reachable only through Tenancy), deleting a tenant would leave orphans, and it
  contradicts the spec's "stored in the tenant's own schema".
- Tenant settings (spatie settings or a JSON column): no history, no row-level atomicity, a cache of its own.
- Redis only: not durable, and a schema backup would lose activation.
- A process-wide singleton cache like `InstalledSolutions`: a worker would keep a deactivated Solution
  alive until restart, the failure mode the spec names.
- A `Cache::remember` per tenant with a TTL: a stale window and cross-process invalidation, to save one
  indexed query. Kept as the path for later: a per-tenant versioned Redis key behind the same
  `ActiveSolutions`, if a load test shows the query matters.
- A single history table with the current state as the latest row: every hot-path read becomes "latest per
  id" and nothing enforces one state per Solution.
- Deleting the row on deactivation: loses the tenant's intent and history, and "off" becomes
  indistinguishable from "never on".
- A `core_` prefix on the table names instead of reserving the ids in Foundation: nothing else in Core is
  named that way.

## Consequences

- Easier: backup, restore and deletion of a tenant carry its activation; the read path needs no landlord,
  no cross-process invalidation and no tenant switch of its own; an entitlement seam adds one more factor
  to the intersection in `ActiveSolutions` and one check in `SolutionActivator::activate`, with no schema
  change.
- Harder: "which tenants have X on" is a walk over schemas, available only to console tooling that
  already walks tenants. A long-running job sees the state as of its first question. On SQLite the schema
  is not switched, so storage isolation between tenants is proved only by the Postgres test
  (`PostgresSolutionActivationTest`); memo isolation is proved on both.
- To maintain: any new reader of the effective state goes through `ActiveSolutions` (raw rows and the journal through the store port) and keeps the memo keyed by
  tenant id; the journal is never edited or pruned.
- Reversal: before release, delete the migration and the code. After release, a tenant migration that
  drops both tables, losing the history; moving the tables to the landlord would mean copying every schema.
