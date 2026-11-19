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
reviewed_at: 2026-11-19
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
  `tests/Architecture/EgressGuardTest.php` (Flow domain, `App\Domains\Credentials` and builder controllers),
  `EgressGuardMiddlewareTest`, `AddressClassifierTest`. Not covered: an extension action that makes
  its own request. Accepted risk: the name lookup (`dns_get_record`, then `gethostbynamel`) has no
  timeout of its own and runs before the HTTP timeout starts, so one `call` can take the system
  resolver's time plus up to 10s; the guard refuses with `transport_failure` once a lookup took more
  than 8s, which stops the HTTP request being added on top. The check runs after the lookup
  returns, so it never cuts a slow lookup short, and a name whose DNS never answers pays the
  resolver timeout twice (`dns_get_record`, then the `gethostbynamel` fallback); only a resolver
  timeout well under the 30s lock TTL keeps that worst case inside it. Accepted at the design gate.
- **A `call` with a saved credential never exposes the secret and never sends it off its allowed hosts.**
  (1) `credential_id` is read as written, never through a template, so a flow variable cannot pick another
  credential; the secret travels in `CallRequest::$credential`, never in `parameters` or `options` (the node's
  input). (2) Only the `http` transport applies a credential (`credential_unsupported` otherwise), and only to
  a host in the credential's allowed hosts (`credential_host_denied`, no connection). (3) The credential's
  headers and query replace the author's of the same name, the inline `auth.*` is ignored, and the author's
  routing headers (`Host`, `X-Forwarded-Host`, `Forwarded`, `X-Original-Host`, `X-Host`) are dropped.
  (4) Such a call never follows a redirect, and authorizing it (an OAuth2 token request) spends the call's own
  `timeout`, so the node stays inside the window its job and lock are sized by. (5) The whole result, error messages and exception text included,
  is masked with `SecretRedactor` before it reaches the ledger, state or logs. Why: whoever edits a flow must
  not be able to read the key or send it to a server of their own (`adr-20261119-credentials-store`). Enforced:
  `tests/Feature/Domains/Flow/Call/CallNodeCredentialTest.php` (`test_the_credential_id_is_not_a_template`,
  `test_the_credential_reaches_the_transport_and_the_secret_is_in_neither_parameters_nor_options`,
  `test_only_the_http_transport_takes_a_credential`), `HttpTransportCredentialTest.php`
  (`test_a_host_outside_the_list_is_refused_without_a_connection`,
  `test_the_authors_routing_headers_are_dropped_so_the_request_lands_on_the_allowed_host`,
  `test_a_redirect_is_not_followed_and_the_credential_goes_nowhere_else`,
  `test_a_token_request_that_spends_the_whole_timeout_sends_nothing_to_the_target`,
  `test_the_secret_echoed_in_the_body_and_a_header_is_masked`,
  `test_an_error_response_and_a_connection_exception_with_the_key_in_the_url_are_masked`),
  `CallTesterCredentialTest.php`. Not covered: a secret the target echoes in a transformed form (URL-encoded,
  re-encoded base64), and a value under 4 characters.
- **Every class in `Handlers/` (except `Abstract` and `Support`) implements `NodeHandlerInterface`.**
  Enforced: `tests/Architecture/HandlerVersionContractTest.php`.
- **Node handlers take collaborators as ordinary constructor dependencies.** The registry keeps
  handler classes and builds a handler on every `resolve()` in the current scope (ADR-0001), so a
  handler never needs to know which of its dependencies are scoped; a `Closure` in a handler
  signature is forbidden. Enforced: `FlowRuntimeIsolationTest::test_node_handlers_do_not_take_resolver_closures`,
  `NodeHandlerScopedDependenciesTest`.
- **Action handlers are kept as classes and built per call; a Solution's action is checked for activation per call.**
  `ActionHandlerRegistry` stores `id => {class, owner}` (owner = Solution id, null = Core) and builds
  the handler through `ActionHandlerFactoryInterface` in the caller's scope, so a handler may take scoped
  services and nothing is kept between jobs. Core's own action ids start with `core.` or `platform.`
  (a Solution's start with its id, by the manifest), so a collision is impossible by construction; a
  duplicate or a post-freeze registration throws. `HandlerTransport` asks `SolutionAvailabilityInterface` on
  every call and returns `solution_inactive` (handler not built) for a Solution the tenant has not
  activated; the singleton transport and registry never hold the scoped `ActiveSolutions`. The Flow
  domain does not depend on the Solution domain. Enforced: `SolutionBoundariesTest::test_flow_does_not_depend_on_the_solution_domain`,
  `HandlerTransportTest`, `ActionHandlerRegistryTest`, `SolutionActionsRuntimeTest`
  (`adr-20261014-solution-actions-registered-per-process`).
- **The handler registry is closed and unambiguous.** No registration after freeze; a handler's
  `version()` must be in its `supportedVersions()`; a duplicate `type@version` is rejected; an
  unknown key throws. Publishing rejects unknown versions. Each entry has an owner (Solution id, null =
  Core): a Core type never contains a dot, a Solution's starts with `<id>.`, and every `category()` is a
  Foundation `NodeCategory` value or the owning Solution's id. Enforced: `NodeHandlerRegistry`,
  `ValidateFlowService`.
- **A Solution's node is checked for activation on every step, before its handler is built.** `FlowEngine`
  asks `SolutionAvailabilityInterface` for the owner and routes the node to `error` with
  `solution_inactive`, `solution_version_missing` (installed, version not registered) or
  `solution_not_installed` (dotted type, no owner); a Core type without a handler still throws. Enforced:
  `FlowEngineSolutionNodeTest`, `SolutionLifecycleTest` (`adr-20261113-solution-node-types-and-state-namespace`).
- **Only whitelisted node types write `system.*` and `rag.*`.** `system.*`: `send_message`,
  `input`, `delay`, `notify`, `set_tag`, `request_operator` (its marker holds the id of the operator request it
  waits for); `rag.*`: `rag_query`. `flow.*` and `call.*` are open to
  every handler. A Solution's namespace (its id, `state: true`) is written only by node types with the same
  owner; no whitelist. Enforced: `SystemStateNamespacePolicy`, applied in `FlowSessionPersister`.
  Adding a writer of `system`/`rag` means editing the whitelist, on purpose.
- **A session runs the definition it started with.** `flow_definition_id` is set once, and
  definitions cannot be deleted while sessions reference them (`restrictOnDelete`).
- **A flow template never starts a session.** Every start path (default flow, trigger, global
  command, event job, subflow) loads its definition through
  `FlowDefinitionRepositoryInterface::findLatestActiveByFlowId()` (a subflow node pinned to a version, through
  `findRunnableByFlowIdAndVersion()`), which return only `runnable()`
  (`kind = flow`) definitions; a template has no trigger (refused on draft save) and is not offered
  by any flow picker. A template runs only as a copy inside the flows that place it, expanded at
  publish (`adr-20261111-flow-templates-expanded-at-publish`). Enforced:
  `FlowTemplatePublishTest::test_a_template_is_never_found_as_a_flow_to_start_and_has_no_trigger`,
  `AssistantSettingsConsoleTest`; a new start path that queries `flow_definitions` itself is review only.
- **Copied template ids contain no dot and are the same in every template version.** Expansion
  re-issues each id as `UUIDv5(reference node, old id)`, because state keys are split on `.` and
  button ids are `uuid` columns and edge handles. Enforced: `FlowTemplatePublishTest`.
- **A flow publish locks the assistant's template drafts before its own draft; the host republish does not.**
  `PublishFlowService::execute()` takes `FOR SHARE` on every `kind = template` draft of the assistant, ordered by
  `flow_id`, then `FOR UPDATE` on its own draft. `publishFlow()` and `republishHosts()` take no such lock: they run
  holding a template and a host, and a second template lock from there deadlocks with that template's own publish.
  Why: otherwise a host placing a template for the first time can miss the template's new version
  (`adr-20261111-flow-templates-expanded-at-publish`). Enforced:
  `FlowTemplatePublishTest::test_a_flow_publish_locks_the_assistants_templates_before_its_own_draft`,
  `FlowTemplatePublishTest::test_a_template_publish_takes_no_shared_lock_on_templates`.
- **Execution is bounded, and loops have a budget of their own.** `StepBudget` fails the
  session with `FlowExecutionLimitExceededException` naming the node when: the steps outside loop bodies pass
  `flow.execution.max_iterations` (entering a loop costs one); one loop iteration takes more than that many steps before
  its `loop_end`; a `loop` node takes its `loop` exit with its persisted iterator past `flow.execution.max_loop_iterations`
  (over the whole run for an automation, so it holds across segments and retries; per engine call for a conversation,
  from the iterator the call found, so a loop that waits for the contact is not stopped after so many messages); or all steps pass the ceiling
  `min(FlowEngineInterface::MAX_STEPS_PER_RUN, max_iterations × (max_loop_iterations + 1))`. A conversation counts per
  outermost engine call — one budget per session, held by the scoped `ExecutionSegment`, so a parent resumed in a
  nested call after its subflow child ended keeps its loop's count, its clock and its steps; an automation run counts over all its segments — steps outside loop bodies in `step_count` (capped by
  `max_iterations`), steps inside them in `loop_step_count` (the sum capped by the ceiling) — and a segment that starts
  inside a loop body knows it from the iterator in state. A conversation call, which has no segments, also fails a loop
  body still running after `FlowEngineInterface::MAX_SEGMENT_SECONDS` (30 s) of the outermost call — subflow children
  it ran synchronously included — so a long loop of slow nodes never outlives the 60 s job timeout and leaves the
  session stuck `active`. An automation also persists the steps of the iteration in progress (`loop_iteration_step_count`,
  reset by a `loop_end` and by the loop's exit), so a runaway inside or after a loop body that never reaches its
  `loop_end` stops at `max_iterations` steps of that iteration however many segments it spans. Settings are read through the scoped
  `PlatformSettingsReaderInterface`; a lost session lock aborts with `SessionLockLostException`. Why: a loop over a long
  list is legitimate and must not trip the shared budget, while a runaway flow must still free its worker
  (`adr-20261118-segmented-automation-runs`, amendment 2026-11-19). Enforced: `LoopEngineTest`, `AutomationRunTest`,
  `StepBudgetTest`, `FlowEngineTest`, `SubflowChildStopHandsBackTest`.
- **An automation run never outlives its job and runs once per fire.** One engine call of a `run_kind = automation`
  session — nested subflow and parent-resume calls included, sharing the scoped `ExecutionSegment` — stops before the
  next node after `flow.automation.segment_steps` or `flow.automation.segment_seconds` (whichever first, at least one
  step), and only the outermost call queues the continuation, after writing a new `segment_id` on the run's root
  session. A continuation runs only while the root carries its `segment_id`, and then runs the run's one `active`
  session (down the `paused_subflow` chain); a start runs only when `(trigger_id, fire_key)` has no run yet, and
  otherwise continues the run's `active` session. Jobs on `flow.automations` keep their timeout below the connection's
  `retry_after`, and the seconds setting's maximum keeps a segment plus the slowest node under that timeout. Why: a
  second runner or a re-reserved job repeats `call` side effects in somebody else's system, silently
  (`adr-20261118-segmented-automation-runs`). Enforced: `AutomationRunTest`, `AutomationRunLockRedisTest`,
  `ExecutionSegmentTest`, `HorizonAutomationsSupervisorTest`.
- **An automation run has a deadline frozen at its start; past it the whole run fails and runs no node.**
  `FlowEngine::start()` writes `deadline_at` = start + `flow.automation.max_run_hours` (24) for `run_kind = automation`
  only, and a subflow child copies its parent's; a row without one counts from `created_at`. Every minute
  `flow:sweep-overdue-automation-runs` (`AutomationRunDeadlineSweeper`, per tenant passed explicitly, stopped tenants
  skipped) fails every live session of a run past its deadline, or still `pending` 3 h after it was created, bottom-up in
  one transaction under the root's `LockScope::ofSession()`: `status = failed`, `failure_reason` `deadline_exceeded` or
  `start_lost`, the root's `segment_id` cleared, `updated_at` written; no node runs and no parent takes its `failed`
  handle. Conversations are never touched. A producer of `pending` rows must write their `deadline_at`. Why: a run
  whose job was lost would keep its trigger and its flow for good, and a setting lowered later must not fail running
  runs at once (`adr-20261119-automation-run-deadline`). Enforced: `AutomationRunDeadlineSweeperTest`,
  `AutomationRunDeadlineSweeperRedisTest`, `SweepOverdueAutomationRunsCommandTest`, `AutomationRunTest`.

## Rules

- **Keep old handler versions registered after a breaking change.** The registry resolves by
  `type@version()` only; `supportedVersions()` is validated but never used for lookup. So a
  handler that declares `[2, 1]` does not serve v1 nodes — the v1 class must stay registered.
  Why: existing definitions keep their node versions forever. This holds for the template
  pass-throughs too: `template@1` and `template_output@1` stay registered for good. Review only.
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
- **A repeated execution of a `call` step is replayed from the ledger, never re-sent; every send carries an
  attempt number.** The node asks `CallExecutionLedgerInterface` for an earlier success of the key before calling
  the transport and records a success after it (a failure is never recorded, so it stays retryable); a Redis
  failure never blocks the call; the builder's test call stays outside the ledger; the attempt number travels in
  `CallRequest::$options` under a key the node always overwrites. The dedup window is the platform setting
  `flow.call.dedup_ttl_hours`, at least Horizon's failed-job retention (a retry from `failed_jobs` past the window re-sends with the same key). A replay is checked before, and is never
  refused by, the `call_executions` quota. Why: otherwise a retried step creates a
  second record at the remote system (`adr-20261118-call-outbound-idempotency-strategy`). Enforced:
  `CallNodeIdempotencyTest`, `RedisCallExecutionLedgerRedisTest` (also the tenant forgetter), `HttpTransportTest`.
- **Running the engine outside `FlowOrchestrator` requires the session lock** through
  `FlowExecutionGuard`, keyed like `MessageRouter` (`contact->tenant_id`, contact, assistant) — or, for a contactless
  automation run, by `(tenant, trigger)` — with the session re-read under the lock; a busy lock throws
  `SessionLockTimeoutException` and the queue retries (the automation jobs and `ResumeDelayedFlowSessionJob` release
  themselves instead of spending an attempt). A caller that may hold a contactless session takes its scope from `LockScope::ofSession()`, never
  builds it from the session's columns (callers that only ever hold a contact's session — buttons, operator requests,
  event starts — still pass the triple). `DelayedSessionResumer`, `ResumeTimedOutSendMessageNodeJob`, `ContinueAfterOperatorRequestJob`,
  `StartFlowFromEventJob`, `SubflowTimeoutSweeper`, `AutomationRunDeadlineSweeper` and `AutomationRunner` follow it
  (`tests/Feature/Redis`); the sweepers skip a parent or run whose lock is busy and retry on their next run, and skip
  with a warning one they cannot lock at all, so one never ends the pass.
- **A contactless session never reaches a contact through an empty id.** Without a contact,
  `ScopedStateReader` refuses `contact.*` and `module.*`, `ContactWriter` refuses every write and
  `LockScope` refuses a scope with neither a contact nor a trigger, all with `ContactlessSessionException`; `FlowEngine::start()` takes a
  contactless run's assistant only from `FlowRunOrigin`, never from the current assistant, and a subflow
  child copies `run_kind` and `trigger_id`. Why: an empty id turns a per-contact read or lock into a
  tenant-wide one, and an ambient assistant attaches a run to whichever assistant a job resolved.
  `OperandResolver` reads `module.*` operands through the context's reader too
  (`adr-20261118-contactless-runs-in-flow-sessions`). Enforced: `ContactlessFlowSessionTest`,
  `ScopedStateReaderTest`, `ContactWriterTest`, `LockScopeTest`, `OperandResolverModuleTest`.
- **Run retention deletes only finished automation runs, and a subflow chain only as a whole.**
  `AutomationRunPruner` (daily `flow:prune-automation-runs`) deletes a session with its `flow_session_history` and
  `flow_logs` only when it is the tenant's (passed explicitly), `run_kind = automation`, in the terminal whitelist
  `AutomationRunRetention::TERMINAL`, and finished (`updated_at`) before the cutoff of its class: succeeded
  (`completed`, or `ended` + `end_status = success`) after `flow.automation_runs.succeeded_retention_days` (7), any
  other terminal status after `flow.automation_runs.failed_retention_days` (30); both are per-install platform settings,
  0 keeps that class. A chain — a root and every session below it by `parent_session_id`, whatever its tenant or kind —
  goes only when every member qualifies, re-checked under `FOR UPDATE`; a child is never picked alone. Why: a deletion
  cannot be undone, so a mistake may only keep too much — a new status is never deleted until it is classified, and a
  partly deleted chain would leave a run trace with a hole. Per install, not per tenant, follows audit and conversation
  retention while spec `data-lifecycle` leaves that question open. A new path that ends a session must write `updated_at`.
  Enforced: `AutomationRunPrunerTest` (status × kind × end status × age matrix, enum completeness, chains, tenant),
  `PruneAutomationRunsCommandTest`.
- **A node that needs a contact is never published into a flow whose trigger gives none.** A handler declares
  `contact`, `none` or `any` through Foundation's `DeclaresContextRequirementInterface` (Solutions too); one that
  declares nothing needs a contact. Under a `schedule`, `webhook` or `api` trigger, validate and publish refuse such a node, and
  any node whose config reads `contact.*` or `module.*` or uses a contact variable, with `node_needs_contact` at
  `nodes.<id>`. Declare `any` only for a handler checked never to use `NodeExecutionContext::$contactId`,
  `$platform` (both `null` there) or the contact writer without a contact, and hands them on only where the
  receiver takes `null` — `call` is `any` because `CallContext::$contactId` is nullable; `subflow` stays `contact`
  until its target is followed. Known gap: a trigger
  changed on draft save goes live before any publish runs this check.
  Why: a contactless run reaches a contact through an empty id or dies on a typed error nobody watches
  (`adr-20261118-node-contact-context-checked-at-publish`). Enforced: `ContactContextValidationTest`.
- **A definition's expression engine is the flow's choice, written explicitly at publish and checked with.** The draft
  holds it (`flow_drafts.expression_engine`); `PublishFlowService` passes the engine on to `ValidateFlowService` and
  `TemplateExpander` and writes it onto the definition — the draft's on a publish, the active definition's on a
  republish by a template (never the host draft's), the template draft's on a template's own publish. A checker never
  looks the engine up by flow id. An unregistered id is refused (`expression_engine_unknown`), and a template is placed
  only in a flow of its engine (`template_engine_mismatch`). Why: a republish must not publish a host's unpublished
  choice, and one definition runs one engine (`adr-20261119-expression-engine-chosen-per-flow`). Enforced:
  `FlowExpressionEngineTest`, `BuilderApiTest::test_validate_checks_the_flow_with_the_engine_its_draft_chose`; a template's own snapshot is review only (no test publishes a template with another engine).
- **New built-in state namespaces go into the foundation `StateNamespace` enum.** Core keeps no enum of
  its own (ADR-0002). The runtime list is `StateNamespacesInterface` (`StateNamespaceRegistry`: built-ins plus
  Solution namespaces, frozen at boot); readers (`ScopedStateReader`, `OperandResolver`) and the branch
  validator ask it, never a hardcoded list. *(proposed)*
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
