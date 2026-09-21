---
id: adr-0002-retire-core-state-primitives
type: adr
status: accepted
date: 2026-09-11
domains:
  - flow
paths:
  - "app/Domains/Flow/State/**"
summary: Why the unused Core state layer (StateReader/StateWriter/NamespaceResolverRegistry) was deleted, superseding ADR-10 D-4
reviewed_at: 2026-09-11
---
# ADR-0002: Flow state is read by `ScopedStateReader` directly; the Core state primitives are retired

Supersedes decision D-4 of `docs/platform/architecture/adr/10-state-writer-semantics.md`
(Amendment · Brownfield Reconciliation).

## Context

D-4 kept a Core state layer — `StateReader`, `StateWriter`, `NamespaceResolverRegistry` with its
resolvers, `FlowState`, `StatePath`, `WriteContext`, `NamespaceWritePolicy` and a local
`App\Domains\Flow\State\StateNamespace` enum — as the "rich" implementation, with the
foundation contracts meant to be thin adapters over it (`ScopedStateReaderAdapter → StateReader`,
`StateWriter` for engine-internal writes).

The adapter was never built. The runtime took another path and the layer was left behind:

- `ScopedStateReader` reads session state, the `Contact` and `DataAccessorRegistryInterface`
  directly; session writes go through `NodeExecutionResult::stateChanges` and
  `FlowSessionPersister`, contact writes through `ContactWriter`.
- Nothing in `app/` resolved the layer; `ModuleResolutionContext::set()` was never called.
  Only `StateContractTest` exercised it.
- It had drifted into contradicting the runtime: its enum knew four namespaces where the
  canonical foundation `StateNamespace` knows six (`contact`, `call`), and its
  `NamespaceWritePolicy` declared `system.*` engine-only while the enforced
  `SystemStateNamespacePolicy` lets whitelisted handlers write it.

## Decision

- The layer is deleted: the classes above, their container bindings and the tests that existed
  only for it.
- `ScopedStateReader` (reads) and `FlowSessionPersister` + `SystemStateNamespacePolicy`
  (session writes) + `ContactWriter` (contact writes) are the state implementation, not
  adapters over something else.
- The canonical namespace list is the foundation enum `Fapost\Foundation\Flow\Enums\StateNamespace`;
  Core has no second enum.

## Alternatives

- **Wire the layer in as D-4 describes.** Rebuild `ScopedStateReader` as an adapter over
  `StateReader`, extend the layer to six namespaces, and reconcile its engine-only `system.*`
  policy with the handler whitelist. A redesign of the runtime's core with no functional gain;
  every behaviour it would provide already exists and is tested.
- **Keep it and document it as dead.** Leaves two contradictory write policies and two
  namespace enums in the code, where the next reader cannot tell which one is authoritative.

## Consequences

- One state model to read and change; no parallel enum or policy to keep in sync.
- Anything D-4 wanted from the richer layer (typed paths, per-namespace resolvers) must be
  added to the live classes if it is ever needed — there is nothing to fall back to.
- `ReservedContactPathException` and `StructuralPathConflictException` stay: `ContactWriter`
  uses them.
