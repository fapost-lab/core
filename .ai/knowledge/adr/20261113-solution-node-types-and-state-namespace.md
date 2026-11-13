---
id: adr-20261113-solution-node-types-and-state-namespace
type: adr
status: accepted
date: 2026-11-13
domains:
  - solution
  - flow
paths:
  - "app/Infrastructure/Solution/**"
  - "app/Domains/Flow/Registry/**"
  - "app/Domains/Flow/State/**"
  - app/Http/Controllers/Builder/NodeTypesController.php
summary: A Solution declares node types (<id>.<name>) and a state namespace (its id) in the manifest and registers handlers per process; palette groups are NodeCategory or the Solution id; activation is checked per step before the handler is built (solution_inactive / not_installed / version_missing take error)
---
# A Solution registers its own node types and owns a state namespace named by its id

## Context

`adr-20261014-solution-actions-registered-per-process` let a Solution register action handlers only and
refused `registerNodeHandler` on purpose ("a Solution registers action handlers, not node types"). The
owner reversed that on 2026-11-13: a Solution must bring nodes and their handlers, with its own form,
branches and state, because a bare `call` with typed parameters is the UI he is moving away from, and the
AI Solution's agent node does not fit a call at all. State namespaces were a closed Foundation enum
("never invented per node") whose writers were whitelisted in `SystemStateNamespacePolicy`, and builder
palette groups were free strings from `category()` in first-seen order.

## Decision

- **Declared in the manifest, registered per process.** Foundation 0.36 adds the optional schema-1 fields
  `extra.fapost.nodes` (types `<id>.<snake_case>`, checked like `actions`) and `extra.fapost.state`
  (boolean). `SolutionCoreRegistrar::registerNodeHandler` accepts a `NodeHandlerInterface` whose `type()`
  is declared; anything else fails the boot, as for actions. `platform:update` reports a declared type no
  handler registers. `NodeHandlerInterface` is unchanged.
- **The dot is the namespace of a type.** Core's types never contain a dot (registration refuses one), and
  a Solution's start with its id and a dot, so a collision is impossible by construction. The registry
  stores `type@version → {class, owner}` and answers `ownerOf(type)`.
- **Palette groups are a contract.** `category()` is a value of Foundation's `NodeCategory` (Core's groups,
  in palette order) or, for a Solution's node, its own id (one group per Solution, labelled with the
  manifest name). Core's handlers are checked against `NodeCategory` too, so `input` and `send_message`,
  which inherited Foundation's default `Core`, moved to `Conversation` and the palette's "Core" group is gone.
  Foundation's `AbstractVersionedHandler::category()` default (`Core`) is therefore invalid: a handler must
  override it. `/node-types` returns each
  type's `group`, `solution` and `available`, and the ordered `groups`.
- **Activation is checked on every step, before the handler is built.** The Flow port
  `ActionAvailabilityInterface` became `SolutionAvailabilityInterface` (same question, now asked by the
  engine as well as `HandlerTransport`). A node of an inactive Solution takes `error` with
  `solution_inactive: <id>`; an unregistered type with a dot takes `error` with
  `solution_not_installed: <prefix>`; a type of an installed Solution at a version it no longer registers takes
  `error` with `solution_version_missing: <id>`; a Core type without a handler still throws. Without an `error` edge
  the session completes, as with any unconnected handle; publishing warns. Publishing does not check
  activation. The builder offers only available types and renders unavailable ones greyed, keeping their
  config.
- **A Solution's namespace is its id.** With `state: true` it owns `<id>.*`; only node types with the same
  owner write it (`SystemStateNamespacePolicy` compares owners, no whitelist). `contact` and `call` joined
  `RESERVED_IDS`, so no Solution id equals a built-in namespace. The enum stays the list of built-ins;
  Core implements Foundation's read-only `StateNamespacesInterface` with built-ins plus Solution
  namespaces, filled at boot. A Solution namespace is stored in the session like `flow` and read like it
  (templates, branch operands, the builder's pickers, which list the namespaces of the tenant's active
  Solutions). `StateNamespace::Rag` is deprecated (removed in Foundation's next major,
  by the AI Solution spec).
- **Validation hook.** A handler may implement Foundation's `NodeConfigValidatorInterface`;
  `ValidateFlowService` calls it at publish after its generic checks (error blocks, warning does not).
  Core's own hardcoded checks stay where they are.

## Alternatives

- Only actions with a config schema on `call@2`: rejected by the owner (UI; the agent is not a call).
- Prefix `<id>_`: a Solution `send` could claim `send_message`; no longer collision-free by construction.
- Namespaces listed freely in the manifest: needs its own collision and naming rules; the id is already
  unique and kept apart from the built-ins.
- Replacing the enum with a registry: breaks the public contract in a minor release.
- An implicit namespace for every Solution with nodes: less ceremony, but neither the manifest nor the
  Solutions screen would say the Solution keeps state.
- Free-string groups as before: typos create groups, a Solution can name a group like another's.
- Refusing to publish a flow with an inactive Solution's node: publishing would depend on a tenant's
  state, unlike actions.
- Leaving an uninstalled Solution's node as `HandlerNotFoundException`: the session stops without a
  terminal state although the prefix already says whose node it is.
- Registering on activation / a registry per tenant: rejected in the actions ADR (cross-process
  invalidation).

## Consequences

- Easier: a Solution ships real blocks with forms, branches and state; collisions are impossible by
  construction; a deactivated or removed Solution degrades a flow to its `error` branch instead of failing.
- Harder: a refused node with no `error` edge ends the session as completed, so analytics count it as a
  completion, as for a refused `call`. An error in any Solution's node registration stops every process (as for actions); every
  Solution node should expose an `error` handle; the engine asks the availability port on each Solution
  step.
- To maintain: a new Core type never contains a dot and returns a `NodeCategory` value; a new palette
  group is a new `NodeCategory` case (Foundation minor); old node versions of a Solution stay registered.
- Out of scope, still refused: `registerDataAccessor`, `registerMigrations`, routes, schedules; the Plugin
  path (`CoreRegistrarInterface` unbound for Plugins); a Solution's own icon/colour (fallback only).
- Reversal: the manifest fields and Foundation interfaces are public from 0.36; undoing them is a
  deprecation in a minor and removal in a major. No data migration; saved nodes would take `error`
  (`solution_not_installed`).
