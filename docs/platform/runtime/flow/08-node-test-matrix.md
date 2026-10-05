# 08. Node Test Matrix

The checklist every new or changed node handler runs through before it merges. The point is to test
more than the happy path: the runtime must stay correct under retry, concurrency and a broken config.

---

## 1) Where handler tests live

There is no per-node `Unit/.../<Node>HandlerTest.php` convention. What exists today:

| Kind | Location | Covers |
|---|---|---|
| Unit | `tests/Unit/Domains/Flow/BuiltInNodeHandlersTest.php` | the built-in handlers that need no database: `send_message`, `input`, `branch`, `delay`, `assign`, `call`, `emit_event`, `end`, `rag_query` (execute logic, config errors, source handles, state changes) |
| Unit | `tests/Unit/Domains/Flow/NodeHandlerRegistryTest.php` | `type@version` registration, duplicates, `supportedVersions()`, freezing, a fresh instance per `resolve()` |
| Unit | `tests/Unit/Domains/Flow/NodeHandlerSchemaSectionsTest.php` | `configSchema()` shape |
| Unit | `tests/Unit/Domains/Flow/FlowDefinitionValidatorTest.php` | the structural graph validator |
| Unit | `tests/Unit/Domains/Flow/ValidateFlowServiceTest.php` | per-node config rules and graph rules that the builder shows |
| Feature | `tests/Feature/Domains/Flow/*NodeHandlerTest.php` | handlers that touch the database or queue: `AuthRequestNodeHandlerTest`, `NotifyNodeHandlerTest`, `SetTagNodeHandlerTest`, `LoopNodeHandlerTest` |
| Feature | `tests/Feature/Domains/Flow/FlowEngineTest.php`, `LoopEngineTest.php`, `DelayNodeResumeTest.php`, `V1WiringSmokeTest.php` | a node running inside the real flow loop |
| Feature | `tests/Feature/Domains/Flow/NodeHandlerScopedDependenciesTest.php` | handlers are built per scope, never keep a stale scoped dependency |
| Architecture | `tests/Architecture/HandlerVersionContractTest.php` | PHPat: every class in `App\Domains\Flow\Handlers` implements `NodeHandlerInterface`; `BranchNodeHandler` does not depend on the database layer |
| Architecture | `tests/Architecture/FlowRuntimeIsolationTest.php` | handlers take no resolver closures; no static state in the runtime |

A new simple node goes into `BuiltInNodeHandlersTest` or gets its own Feature test, depending on
whether it needs the database. Architecture tests are PHPat rules: they run with the architecture
suite, not with a plain `--filter` on a Feature test.

---

## 2) Minimum for any node

| Category | What to check | Where |
|---|---|---|
| Contract | `type()`, `version()`, registration in the registry | Unit (`NodeHandlerRegistryTest`, `BuiltInNodeHandlersTest`) |
| Happy path | correct `NodeExecutionResult` | handler test |
| Invalid config | `InvalidNodeConfigException` or a `failed` result | handler test |
| Source handle | expected handle for branching | handler test |
| State changes | right keys and values, only in namespaces the node may write (`SystemStateNamespacePolicy`) | handler test |
| Integration | the node really runs inside the flow loop | Feature (`FlowEngine`) |

---

## 3) Extended checklist by node shape

### A. Input-like

- no `incoming` -> `Waiting`
- valid `incoming` -> `Executed` + `default`
- invalid `incoming` -> the contract's invalid/`Waiting` path
- the result is written to the configured variable target
- replaying the same update creates no second side effect

### B. Branch-like

- match on the first rule, fall back to `default`
- type edge cases (string / number / null / empty)
- `logResolved` is populated

### C. Sender-like

- payload built from config, state and language
- idempotent resend (a replay does not send twice; `system.sent_messages` markers)
- sender failure is handled

### D. External call

- success, timeout / network failure, non-2xx response
- retry-safe: the idempotency key (`{sessionId}:{nodeId}`) prevents duplicates on the remote side

### E. Delay / async

- the first run records the schedule marker and returns `Waiting` (or `Delayed` with `resumeAt`)
- a re-run before the resume time does not schedule again
- the resume path continues correctly (`context->resumedAfterDelay`)
- no endless wait because of a missing marker

---

## 4) Graph compatibility

Through `FlowDefinitionValidatorTest` and `ValidateFlowServiceTest`:

- a node with a new `type@version` validates once its handler is registered,
- an unregistered handler gives the expected error,
- required config fields from `configSchema()['required']` are enforced,
- terminal-node rules (`end`, keyboard `send_message`, `loop_end`) hold.

---

## 5) Concurrency and retry

| Scenario | Expected behaviour |
|---|---|
| Job retried after a lock miss | consistent result, no duplicates |
| Optimistic-lock conflict | the orchestration retry reaches the right final state |
| Two near-simultaneous inbound events | no double send or double state change |
| Same update id twice | deduplication prevents repeated side effects |

---

## 6) What to fake in unit tests

- the outbound HTTP client and `MessageSenderInterface`,
- time (`now()` / `Carbon::setTestNow`) for delay-like cases,
- the data accessor for `module.*` paths,
- `DelayResumeSchedulerInterface`, `FlowTriggerEventPublisherInterface` and other queue ports.

---

## 7) Gate before merging a new node

- [ ] The handler is registered in `FlowServiceProvider::registerCoreNodeHandlers()` and listed by `NodeTypesController`.
- [ ] Happy path, invalid config and edge paths are tested.
- [ ] At least one test runs it through `FlowEngine`.
- [ ] Idempotency / re-run is checked.
- [ ] The node's `docs/reference/specs/flow-engine/nodes/` spec and the registered-nodes catalog are updated.
- [ ] A new top-level `system.*` / `rag.*` writer is added to `SystemStateNamespacePolicy` on purpose.

If any item is open, the node is not done.
