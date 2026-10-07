# Roadmap — Solution activation lifecycle

Destination: a Solution package installed with Composer declares itself, is activated for one
tenant from the panel, and its action handlers run in that tenant's flows — proven end to end by a
test, not by hand.

## Phase 1 — A Solution can declare itself

Goal: the platform knows what an installed package claims to provide, and refuses a package that
claims it wrongly. Done when: `platform:update` fails on a malformed manifest naming the offending
field, and passes on a valid one.

- [ ] A Solution declares its identity, its Foundation constraint and what it registers in a
      manifest that is validated at `platform:update`
- [ ] Per-tenant activation storage and registry: a Solution is on or off for one tenant, and the
      state survives a worker restart without leaking between tenants (after: the manifest — there
      is nothing to store the activation of until a package declares an identity)

## Phase 2 — An activated Solution actually runs

Goal: activation changes what a flow can do. Done when: a `call` node reaches a handler that only
an activated Solution provides, and stops reaching it after deactivation.

- [ ] Core implementation of `CoreRegistrarInterface`: an activated Solution's action handlers
      resolve in the flow runtime, and a name collision with Core fails at registration instead of
      overwriting (after: activation storage — registration has to ask what is active)
- [ ] Lifecycle test suite over the whole chain: install → activate → handler available →
      deactivate → gone, with the tenant boundary asserted

## Phase 3 — An operator can do it without a developer

Goal: turning a Solution on is a panel action, not a deploy. Done when: a staff user with the right
permission activates and deactivates a Solution for their tenant and sees the result in a flow.

- [ ] Activation screen on the kit (console/admin): install state, activate, deactivate, what the
      Solution registers through the ui-foundation extension contract, and the reason when its kit
      range does not match Core's (after: activation storage — the screen edits that state; after:
      ui-foundation phase 3 — the screen is built on the kit)
- [ ] A Solution's prebuilt UI reaches the console through the agreed publish contract — installed
      by composer overlay and derived image, no rebuild (after: ui-foundation phase 4 — the
      extension contract it registers through; after: first-solution phase 0 — D4 is resolved
      there as prebuilt bundles)

## Phase 4 — An update cannot take the install down

Goal: a broken or failing Solution degrades only itself. Done when: a Solution whose boot throws
leaves the install serving every other flow, and a Solution migration that fails during
`platform:update` is rolled back alone while the platform migrations stay applied.

- [ ] Failure isolation at boot: a Solution that fails to boot is marked degraded, new sessions of
      its flows do not start, running ones finish (after: activation storage — degraded is a state
      of an activation)
- [ ] `platform:update` with per-phase rollback: validate, migrate platform, migrate each Solution
      separately, boot validation, activate (after: the manifest — phase 1 validates it)

## Waves

1. A Solution declares its identity, its Foundation constraint and what it registers in a manifest that is validated at `platform:update`
2. Per-tenant activation storage and registry: a Solution is on or off for one tenant, and the state survives a worker restart without leaking between tenants
3. Core implementation of `CoreRegistrarInterface`: an activated Solution's action handlers resolve in the flow runtime, and a name collision with Core fails at registration instead of overwriting; Activation screen on the kit (console/admin): install state, activate, deactivate, what the Solution registers through the ui-foundation extension contract, and the reason when its kit range does not match Core's; `platform:update` with per-phase rollback: validate, migrate platform, migrate each Solution separately, boot validation, activate
4. A Solution's prebuilt UI reaches the console through the agreed publish contract — installed by composer overlay and derived image, no rebuild; Failure isolation at boot: a Solution that fails to boot is marked degraded, new sessions of its flows do not start, running ones finish; Lifecycle test suite over the whole chain: install → activate → handler available → deactivate → gone, with the tenant boundary asserted

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
-->
