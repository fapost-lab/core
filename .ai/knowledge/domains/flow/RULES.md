---
id: rule-flow
type: rule
status: active
summary: Runtime isolation, closed handler registry, system.* whitelist, retry safety, versioning
domains:
  - flow
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Flow/**"
  - "app/Infrastructure/Flow/**"
  - "app/Jobs/Flow/**"
  - "app/Console/Commands/Flow/**"
  - config/flow.php
  - "tests/*/Domains/Flow/**"
  - tests/Architecture/FlowRuntimeIsolationTest.php
  - tests/Architecture/HandlerVersionContractTest.php
reviewed_at: 2026-09-11
---
# Flow rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **The runtime does not use facades, the container or `Application`.** Covers the engine,
  `Handlers`, `Orchestration`, `Registry`, `Routing`, `State`, `Subflow`. Why: handlers must be
  constructible and testable without a booted framework, and a hidden container lookup inside a
  worker resolves whatever tenant state is current. Enforced: `tests/Architecture/FlowRuntimeIsolationTest.php`.
  Not covered: `Concurrency`, `Call`, `Expression`, the rest of `Services`.
- **Every class in `Handlers/` (except `Support`) implements `NodeHandlerInterface`.**
  Enforced: `tests/Architecture/HandlerVersionContractTest.php`.
- **Node handlers take collaborators as ordinary constructor dependencies.** The registry keeps
  handler classes and builds a handler on every `resolve()` in the current scope (ADR-0001), so a
  handler never needs to know which of its dependencies are scoped; a `Closure` in a handler
  signature is forbidden. Enforced: `FlowRuntimeIsolationTest::test_node_handlers_do_not_take_resolver_closures`,
  `NodeHandlerScopedDependenciesTest`.
- **The handler registry is closed and unambiguous.** No registration after freeze; a handler's
  `version()` must be in its `supportedVersions()`; a duplicate `type@version` is rejected; an
  unknown key throws. Publishing rejects unknown versions. Enforced: `NodeHandlerRegistry`,
  `FlowDefinitionValidator`.
- **Only whitelisted node types write `system.*` and `rag.*`.** `system.*`: `send_message`,
  `input`, `delay`, `notify`, `set_tag`; `rag.*`: `rag_query`. `flow.*` and `call.*` are open to
  every handler. Enforced: `SystemStateNamespacePolicy`, applied in `FlowSessionPersister`.
  Adding a writer means editing the whitelist, on purpose.
- **A session runs the definition it started with.** `flow_definition_id` is set once, and
  definitions cannot be deleted while sessions reference them (`restrictOnDelete`).
- **Execution is bounded.** The engine stops after `flow.execution.max_iterations`; a lost
  session lock aborts with `SessionLockLostException`.

## Rules

- **Keep old handler versions registered after a breaking change.** The registry resolves by
  `type@version()` only; `supportedVersions()` is validated but never used for lookup. So a
  handler that declares `[2, 1]` does not serve v1 nodes — the v1 class must stay registered.
  Why: existing definitions keep their node versions forever. Review only.
- **A handler's side effect must be safe to repeat.** Markers are persisted after the side
  effect, in a separate step (`FlowEngine`), so a crash between the two repeats the effect on
  retry. Check a marker before acting, and rely on a downstream idempotency key where one exists
  (`MessageSender`'s `msg:sent:*`). *(inferred from the code; proposed as a rule)*
- **Running the engine outside `FlowOrchestrator` requires the session lock** through
  `FlowExecutionGuard`. `StartFlowFromEventJob`, `ResumeTimedOutSendMessageNodeJob` and the
  subflow sweeper call the engine directly today. *(proposed)*
- **New state namespaces go into the foundation `StateNamespace` enum.** Core keeps no enum of
  its own (ADR-0002). *(proposed)*
- **A singleton in `FlowServiceProvider` must not receive a scoped service in its constructor.**
  It is transitive: a `bind` service that holds a scoped one (`CachedContentTranslator` holds the
  tenant context, current assistant and `TenantSettings`) counts as scoped. Make the consumer
  `scoped` (as `VariableResolverInterface` is), or keep classes
  and build per scope through a factory port implemented in `app/Infrastructure` (as the node
  handler registry does, ADR-0001). A resolver closure is not the answer: a constructor
  `Closure` is only for breaking a construction cycle (`SubflowStarterService::engineResolver`).
  Why: registries are singletons for the worker's lifetime, so a captured scoped object leaks
  the first job's tenant and assistant into later jobs. Review only, apart from the handler
  rule above.
