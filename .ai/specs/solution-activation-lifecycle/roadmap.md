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

- [ ] Filament activation screen: install state, activate, deactivate, and what the Solution
      registers (after: activation storage — the screen edits that state)
- [ ] A Solution's builder components reach the builder through the agreed publish contract, with
      the rebuild requirement either removed or stated on the screen (after: D4 in `spec.md` — the
      answer decides whether this is a publish step or a build step)

## Waves

1. The manifest and its validation
2. Activation storage and registry; the Core registrar and the lifecycle suite
3. The activation screen; the builder-component publish path

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
