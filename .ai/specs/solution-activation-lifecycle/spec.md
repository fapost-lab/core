# Solution activation lifecycle

Depth: normal — the shape of the extension surface is half-decided in code and half-open; every
remaining decision is one question, and the irreversible ones (manifest format, activation storage)
are named below.

## Idea

Step 4 of the product roadmap, in the owner's words: "Solution activation lifecycle — manifest
validation, activation storage and registry, the activation screen, and the lifecycle tests that
prove install → activate → handler available."

The platform is meant to be extended from outside through public contracts. Today a Solution can
be written, but nothing installs it: there is no way to say which Solutions a tenant runs, no
validation that a package declares what it needs, and no screen that turns one on.

## Goal and problem

- Who is worse off without this, and how: the integrator — the platform's primary external
  audience — can write a Solution package but cannot put it in front of a tenant. The owner's own
  client rollouts stay hand-wired, which is exactly the cost the platform exists to remove. Step 6
  (the first solution built through the public contracts) cannot start at all: there is nothing to
  install into.
- What is true when the work is done: a Solution package is installed with Composer, declares
  itself through a manifest that fails loudly when it is wrong, is activated per tenant from the
  admin panel, and its action handlers are resolvable in a flow immediately afterwards — proven by
  a test that walks install → activate → handler available, not by hand.

## Stress test

- Hidden assumptions — "this holds only if …":
  - Activation is per tenant, not per install. Holds only while the open distribution keeps the
    multi-tenant runtime in a single-tenant install; if a future install shape drops tenancy, the
    activation store moves with it.
  - A Solution's back-end registration is enough to make it useful. False for any Solution that
    ships an interface: its Vue components reach the builder only through the Vite glob, which is
    a build-time contract. Resolved 2026-10-07: a Solution's UI ships prebuilt and is loaded at run
    time (D4 below), so this holds for UI too, provided the kit range matches.
  - The manifest can be validated without executing the package. Holds only if the manifest is
    declarative data; a manifest that is PHP code cannot be checked before it is loaded.
- The main trade-off: validating a manifest at `platform:update` catches a broken package before a
  tenant sees it, at the cost of a deploy-time failure path that must be actionable — a package
  that refuses to load must say which field is wrong, or the integrator is stuck with a blank panel.
- The weakest point: there is no real Solution to design against. `HrSolutionServiceProvider` is an
  empty stub, so every decision here is made against an imagined consumer until step 6 runs. This
  is why step 6 follows directly and why contract changes are expected to come back from it.
- Failure modes — cause, what breaks, the signal that shows it:
  - A Solution registers a handler for a type Core already owns — the flow resolves the wrong
    handler and a live session behaves differently after activation; the signal is a registry
    collision, which must be a hard failure at registration, not a silent overwrite.
  - Activation state is cached per process — a Horizon worker keeps a deactivated Solution alive
    for the rest of its life, across tenants; the signal is a handler resolving for a tenant that
    never activated it.
  - A tenant activates a Solution whose contracts target a newer Foundation — the failure surfaces
    deep inside a running session instead of at activation time.
- Other shapes considered, and why this one:
  - Activation as a config file per install — rejected: it cannot differ per tenant, and the open
    distribution's single-tenant install is still a tenant.
  - No activation at all, a Solution being active as soon as it is installed — rejected: it removes
    the operator's control and makes every registry collision a deploy-time accident.

## Scope and non-goals

- In scope: manifest format and its validation, activation storage and registry, the Core
  implementation of `CoreRegistrarInterface`, the per-tenant activation screen on the kit (console/admin, after ui-foundation),
  publishing a Solution's prebuilt UI through the agreed publish contract, and the lifecycle test
  suite that proves the chain end to end.
- Not doing: the marketplace and third-party distribution (step 8 — an extension reaching another
  installation is a separate question, and the sandbox boundary cannot be stated before this
  surface is fixed); writing the first Solution itself (step 6); the SaaS shell's plan → activation
  mapping, which belongs to the owner's separate closed product.

## Decisions

- Solutions are activated per tenant, stored in the tenant's own schema — rejected: an install-wide
  config file, because a single-tenant install is still a tenant and the store would have to move.
- Registration goes through the existing registries (`ActionHandlerRegistry`, and the Core
  implementation of `CoreRegistrarInterface` that does not exist yet) — rejected: a parallel
  Solution-only registry, because Core already resolves `call` handlers by name and two registries
  would resolve differently.
- A Solution registers action handlers, not new node types — settled by the Flow Engine rules in
  `AGENTS.md`: a node contract change is a Core handler version, so a package cannot add node types.
- The manifest is validated at `platform:update`, with a failure that names the offending field —
  rejected: validating lazily at activation, because a broken package then reaches the operator's
  screen before anything checks it.
- Extension contracts live in `fapost/foundation`, consumed from their own repository; any contract
  change this work needs is made there, not in Core (ADR-05).
- Changed 2026-10-07: the activation screen is built on the kit (Inertia + Vue), not Filament, and
  also shows a kit-range mismatch as the reason a Solution is not activated — Core's operator UI
  leaves Filament (`ui-foundation`), and no new screen is built on it.
- Changed 2026-10-07: a Solution registers its UI through the ui-foundation extension contract
  (an application in the switcher, contextual actions, a shell slot, dashboard widgets, settings
  tabs, table columns), not "Filament resources" — superseded together with ADR-06's build-time-only
  rule (ADR planned in ui-foundation phase 4).
- Installation is a composer overlay (`composer.overlay.json`) and a derived image, with no Node
  and no rebuild of Core (`first-solution` phase 0).

## Earlier design carried over (2026-10)

The pre-Jig platform notes (`docs/platform/architecture/platform/12-solutions-modules.md`,
`13-self-hosted.md`, `15-open-questions.md`, removed when this was recorded) sketched more than the
decisions above settle. Kept here as input, not as decisions:

- What a Solution may register: action handlers (not node types — see Decisions), flow templates
  for its niche, its own tenant-schema migrations, `DataAccessorInterface` implementations, UI through
  the ui-foundation extension contract (was: Filament resources — superseded 2026-10-07), scheduled jobs, and roles/permissions through the registrar.
- Manifest sketch: `module`, `version`, `requires_platform` (a semver range) and
  `requires_capabilities` (e.g. `flow.rag_adapter`, `messaging.broadcast`, `contacts.attributes`).
- Failure isolation: a handler exception fails the session with a fallback message; a Solution that
  fails to boot leaves the install degraded — new sessions of its flows do not start, running ones
  finish; a failed migration during an update rolls back that Solution only.
- `platform:update` in five phases, each rolled back on its own rather than globally: validate
  (manifests, capabilities, migration conflicts — abort with nothing touched), migrate platform,
  migrate each Solution separately, boot validation (build registries, check capabilities),
  activate (warm caches). Today `platform:update` does not exist; `Commands/Platform` holds only
  install commands.

## Open questions

- Resolved 2026-10-07 — D4 (from the product roadmap): does an installed Solution have to be
  rebuilt into the front-end bundle before its UI appears? No: the UI ships prebuilt in the
  package's `dist/` and is loaded through Core's import map; installation is a composer overlay
  plus a derived image, with no Node. Decided in `first-solution` phase 0 and `ui-foundation`
  (supersedes ADR-06's build-time-only rule). Cost: the kit becomes a run-time interface, and the
  activation screen must say why a Solution with a mismatched kit range is not activated.
- What happens to a tenant's running sessions when a Solution is deactivated mid-flow — fail the
  session, or let the current session finish on the handler it started with.
- Whether a Solution may declare a Foundation version constraint that activation checks, or whether
  Composer's own resolution is the only gate.
- Cross-Solution migration conflicts: a linter in CI, or a check at `platform:update`.
- What happens to a Solution's tables when it is deactivated or removed: kept, or soft-deleted.
- Role and permission names from two Solutions colliding: namespaced by Solution, or rejected at
  registration.
- Which Solution follows the first one — decided by demand after step 6, not here.

## Assumptions left untested

- That the `HrSolutionServiceProvider` stub reflects the shape a real Solution provider will want —
  taken at normal depth; step 6 tests it by building one for real.
- That per-tenant activation state can be read on the hot path without a cache that outlives a job —
  taken at normal depth; a load test against an activated Solution would test it.
