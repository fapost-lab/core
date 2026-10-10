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
  - tests/Architecture/EgressGuardTest.php
reviewed_at: 2026-10-05
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
- **Tenant-steered HTTP goes only through `GuardedHttpClient`.** The `call` node takes its target
  from the flow author, often through a template over contact data, so a request never connects to a
  private, loopback, link-local, reserved or cloud-metadata address: the guard resolves the name
  itself, refuses the request if any address is not public, pins the connection to the checked
  addresses (`CURLOPT_RESOLVE`) and re-checks every redirect hop. There is no switch to turn it off;
  the operator widens it with `FLOW_EGRESS_ALLOW`, which can never cover the metadata addresses.
  Why: without it a flow reads Redis, Postgres or the cloud credentials service from inside the
  network. New Flow code that needs HTTP uses `GuardedHttpClient::request()`, not `Http::` or
  Guzzle; hosts the operator chose (Telegram, captcha) are outside this rule. Enforced:
  `tests/Architecture/EgressGuardTest.php` (Flow domain and builder controllers),
  `EgressGuardMiddlewareTest`, `AddressClassifierTest`. Not covered: an extension action that makes
  its own request. Accepted risk: the name lookup (`dns_get_record`, then `gethostbynamel`) has no
  timeout of its own and runs before the HTTP timeout starts, so one `call` can take the system
  resolver's time plus up to 10s; the guard refuses with `transport_failure` once a lookup took more
  than 8s, which stops the HTTP request being added on top. The check runs after the lookup
  returns, so it never cuts a slow lookup short, and a name whose DNS never answers pays the
  resolver timeout twice (`dns_get_record`, then the `gethostbynamel` fallback); only a resolver
  timeout well under the 30s lock TTL keeps that worst case inside it. Accepted at the design gate.
- **Every class in `Handlers/` (except `Abstract` and `Support`) implements `NodeHandlerInterface`.**
  Enforced: `tests/Architecture/HandlerVersionContractTest.php`.
- **Node handlers take collaborators as ordinary constructor dependencies.** The registry keeps
  handler classes and builds a handler on every `resolve()` in the current scope (ADR-0001), so a
  handler never needs to know which of its dependencies are scoped; a `Closure` in a handler
  signature is forbidden. Enforced: `FlowRuntimeIsolationTest::test_node_handlers_do_not_take_resolver_closures`,
  `NodeHandlerScopedDependenciesTest`.
- **The handler registry is closed and unambiguous.** No registration after freeze; a handler's
  `version()` must be in its `supportedVersions()`; a duplicate `type@version` is rejected; an
  unknown key throws. Publishing rejects unknown versions. Enforced: `NodeHandlerRegistry`,
  `ValidateFlowService`.
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
- **A `call` node's `CallContext::$idempotencyKey` is the engine's execution key plus the node id**
  (`CallNodeHandler::executionKey()`), never `{session}:{node}`. Why: that value repeats on every
  loop pass, so a transport or receiver deduplicating by it (`HttpTransport` sends it as
  `Idempotency-Key`) would swallow every call after the first; the engine key changes with each
  persisted step and survives a queue retry of the same step. The `call_executions` unit uses the
  same value. Enforced: `CallNodeExecutionKeyTest`.
- **Running the engine outside `FlowOrchestrator` requires the session lock** through
  `FlowExecutionGuard`, keyed like `MessageRouter` (`contact->tenant_id`, contact, assistant),
  with the session re-read under the lock; a busy lock throws `SessionLockTimeoutException` and
  the queue retries. `DelayedSessionResumer`, `ResumeTimedOutSendMessageNodeJob`, `StartFlowFromEventJob` and
  `SubflowTimeoutSweeper` follow it (`tests/Feature/Redis`); the sweeper skips a parent whose lock
  is busy and retries on its next run.
- **New state namespaces go into the foundation `StateNamespace` enum.** Core keeps no enum of
  its own (ADR-0002). *(proposed)*
- **A singleton in `FlowServiceProvider` must not receive a scoped service in its constructor.**
  It is transitive: a `bind` service that holds a scoped one (`CachedContentTranslator` holds the
  tenant context, current assistant and `TenantSettings`) counts as scoped. Make the consumer
  `scoped` (as `VariableResolverInterface` is), or keep classes
  and build per scope through a factory port implemented in `app/Infrastructure` (as the node
  handler registry does, ADR-0001). A resolver closure is not the answer: a constructor
  `Closure` is only for breaking a construction cycle (`SubflowStarterService` and
  `DefaultSubflowResumer` each take a lazy `engineResolver`).
  Why: registries are singletons for the worker's lifetime, so a captured scoped object leaks
  the first job's tenant and assistant into later jobs. Review only, apart from the handler
  rule above.
