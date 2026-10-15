---
id: adr-20261014-solution-actions-registered-per-process
type: adr
status: accepted
date: 2026-10-14
domains:
  - solution
  - flow
paths:
  - "app/Domains/Solution/**"
  - "app/Infrastructure/Solution/**"
  - "app/Infrastructure/Flow/**"
  - "app/Domains/Flow/Action/**"
summary: Each valid Solution registers its action handlers once per process through its own Core registrar (checked against the manifest, boot fails on violations); the registry stores classes and owners, builds handlers per call, and activation is checked per call (solution_inactive)
---
# Every installed Solution registers its actions once per process through its own registrar; activation is checked at call time

## Context

`adr-20261014-solution-activation-in-tenant-schema` makes activation per tenant and expects the handler
registry to stay tenant-free. Foundation 0.13 had one global `CoreRegistrarInterface` that the base
provider fetched from the container in `boot()`: it did not know which Solution was calling, so Core could
not check a registration against that Solution's manifest, and the interface had no method for an action
handler at all (the spec says a Solution registers action handlers, not node types). Laravel boots a
Solution's provider (registered inside `SolutionServiceProvider::register()`) before that provider's own
`boot()`, so registering from the Solution provider's `boot()` would reverse the documented order
`registerExtensions` then `bootSolution`. Workers are long-lived: the registry and the transport are
singletons and must not hold a scoped service, and a deactivation must reach the next job of any process.

## Decision

- Foundation 0.17 adds `CoreRegistrarInterface::registerActionHandler(class-string)` and a final public
  `AbstractSolutionServiceProvider::registerExtensionsWith($registrar)`; the base `boot()` only runs
  `bootSolution()` and no longer asks the container for a registrar. Breaking for 0.x, no Solution existed
  outside Core's fixtures.
- Core gives each valid Solution its own registrar: port `Solution/Contracts/SolutionRegistrarFactoryInterface`
  (bound in `SolutionServiceProvider`), implementation `App\Infrastructure\Solution\SolutionCoreRegistrarFactory` and
  `SolutionCoreRegistrar`. Infrastructure holds the composition of two domains (manifest and Flow registry);
  neither domain depends on the other (`SolutionBoundariesTest`).
- `SolutionServiceProvider::register()` adds an `$app->booting()` callback that calls
  `registerExtensionsWith()` on each Solution's provider: after every `register()`, before any `boot()`, so
  before `bootSolution()` and before the registries freeze in `booted()`.
- Registration is strict and any violation is an exception at boot, naming Solution, package, action id and
  class: an action id not in `extra.fapost.actions`; a duplicate id (the message names both owners); a class
  that is not an `ActionHandlerInterface`; `registerNodeHandler` (a Solution registers action handlers, not
  node types); `registerDataAccessor`, `registerRagAdapter`, `registerRoutes`, `registerSchedule`,
  `registerMigrations` (not supported for a Solution yet). Core's own actions must start with `core.` or
  `platform.`, which, with the manifest rule that a Solution action starts with its id, makes a collision
  between Solutions or with Core impossible by construction.
- `ActionHandlerRegistry` stores `id => {class, owner}` (`owner` = Solution id, null = Core) and builds the
  handler on every `get()` through `ActionHandlerFactoryInterface` (`ContainerActionHandlerFactory`), in the
  scope of the caller (the ADR-0001 approach). One throwaway instance is built at registration to read `id()`.
- Activation is checked per call. `HandlerTransport` asks `ActionAvailabilityInterface` (Flow port;
  `ContainerActionAvailability` in `App\Infrastructure\Solution` resolves the scoped `ActiveSolutions` and the tenant context
  from the container on each question, so the singleton transport holds no scoped service). A Solution's
  action for a tenant that has not activated it returns `CallResult::error('solution_inactive')` before the
  handler is built; `CallNodeHandler` routes it to the `error` handle and sets `errorMessage =
  'solution_inactive: <solution id>'` because the flow log keeps only that field. Without a tenant the answer
  is "not available". The runtime node and the builder's `CallTester` share the transport, so both obey.
- The quota is spent before the transport, as it is for `egress_denied` and `action_not_found`: a refused
  call is one call execution.
- The builder's action list is the registry filtered by the same port: Core's actions and the active
  Solutions' actions for the builder's tenant. The UI is unchanged (it already accepts a saved value outside
  the list). Publishing does not check that an action's Solution is active.
- A declared-but-unregistered action is not a boot error; `platform:update` (also `--validate-only`) reports
  `<package>: extra.fapost.actions[<i>]: "<id>" is declared but not registered` and exits 1 before anything
  is migrated.
- A session running while its Solution is deactivated is not failed: its next `call` takes the `error`
  handle (answers the spec's open question).
- `onActivate`/`onDeactivate` are still not called (no consumer yet; context and guarantee undecided).
  Moving the egress guard into `fapost/support` stays with the first Solution that has an HTTP action
  (`adr-20261010-egress-guard-in-core`); `extending/solutions` states the requirement for now.

## Alternatives

- One global registrar from the container: cannot tell which Solution registers, so no manifest check, and
  Solution A could take `b.*`.
- Registering from `SolutionServiceProvider::boot()`: Solution providers boot earlier, `bootSolution` would
  precede `registerExtensions`.
- A Foundation factory that `boot()` asks by provider class: a new contract for the same thing, and two
  Solutions sharing a provider class are ambiguous.
- The registrar in the Flow or Solution domain: Flow would learn manifests, or Solution would depend on Flow.
- A registry per tenant, or registering on activation: the registry would be rebuilt and frozen in every
  scope, and a deactivation would need invalidation across processes.
- Resolving the owner from the id prefix at call time instead of storing it: works with today's manifest
  rules, but the explicit owner survives future rules (Plugins).
- `registerActionHandler(string $id, string $class)` without a throwaway instance: cheaper at boot but a
  mismatch with `id()` moves to runtime.
- Refusing as `action_not_found`: hides the cause from the flow author; an installed package is not secret.
- Checking before the quota is spent: the node would have to know actions and owners.
- Dropping a violating Solution instead of failing the boot: better operationally, but it is the isolation
  of phase 4 and needs buffered registration and a degraded state.

## Consequences

- Easier: one rule path for Core and Solutions; a deactivation takes effect for the next job in every
  process; the registry needs no tenant and no invalidation; a Solution handler may depend on scoped services.
- Harder: a registration violation in any installed Solution stops every process (web, worker, artisan) until
  it is fixed or the package is removed; it surfaces at deploy (`platform:update`) rather than at a client.
  A handler constructor runs at boot outside any tenant, so it must be cheap and tenant-free.
- To maintain: the registrar rejects unsupported methods loudly; supporting one means a decision about its
  rules. Any new Core action starts with `core.` or `platform.`. Anything new on the call path asks
  `ActionAvailabilityInterface`, never a scoped service held by a singleton.
- Reversal: before release, delete the code. After release, Solutions written for Foundation 0.17 depend on
  `registerActionHandler` and `registerExtensionsWith`; reversing is a new Foundation minor with a
  deprecation. No data is written and there is no migration.
