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
reviewed_at: 2026-11-25
---
# Flow rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **The runtime does not use facades, the container or `Application`.** Covers the engine,
  `Handlers`, `Orchestration`, `Registry`, `Routing`, `State`, `Subflow`, `Trace`. Why: handlers must be
  constructible and testable without a booted framework, and a hidden container lookup inside a
  worker resolves whatever tenant state is current. Enforced: `tests/Architecture/FlowRuntimeIsolationTest.php`.
  Not covered: `Concurrency`, `Call`, `Expression`, the rest of `Services`.
- **Tenant-steered HTTP goes only through `GuardedHttpClient`.** The `call` node takes its target
  from the flow author, often through a template over contact data, so a request never connects to a
  private, loopback, link-local, reserved or cloud-metadata address: the guard resolves the name
  itself, refuses the request if any address is not public, pins the connection to the checked
  addresses (`CURLOPT_RESOLVE`) and re-checks every redirect hop. There is no switch to turn it off;
  the operator widens it with `FLOW_EGRESS_ALLOW`, which can never cover the metadata addresses.
  The guard also refuses, on every hop and whatever the allowlist says, a flow webhook URL of this installation
  (`/hooks/flow/…` on the `APP_URL` host, `tenancy.base_domain` or any host under it, other tenants' included,
  reason `own_flow_webhook`): two flows whose `call` nodes post to each other's webhook would loop with no event to
  count the chain. A loop guard, not a boundary — a DNS alias or an outside relay gets past it.
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
- **A one-time code of `auth_request@2` never leaves `AuthCodeStore` and the job that delivers it.** The node
  generates it with `random_int`, keeps only an HMAC-SHA256 under a key derived from `APP_KEY` (over tenant, session,
  node and code) in the cache with the code's TTL, compares with `hash_equals`, marks it used on success (a retried step still matches;
  the node's next challenge replaces it) and drops it on used-up attempts and on every `failed` exit. The state holds only counters (`system.auth.<node>`), the metadata a masked
  destination; the code travels to the sender only in `SendOneTimeCodeJob`, which is `ShouldBeEncrypted`, runs once
  behind a cache guard per send, and sends the mail synchronously (`OneTimeCodeMail` and `OneTimeCodeMessage` refuse
  serialization). The node trace drops the incoming text of an `auth_request` node. One destination gets at most
  5 codes an hour per tenant, whatever flow asks. Why: a code in state, a trace, a log or a plain queue payload lets
  staff or an operator authenticate as the contact, and a flow that resets the counters can brute-force it
  (`flow-auth-request`, design gate D1–D10). Enforced: `tests/Feature/Domains/Flow/AuthRequestV2NodeHandlerTest.php`
  (`test_the_code_reaches_neither_state_nor_metadata_nor_the_prompt`, `test_wrong_codes_use_up_the_attempts_and_the_code_is_dropped`,
  `test_a_retried_step_keeps_the_code_already_on_its_way`, `test_one_address_gets_at_most_five_codes_an_hour`),
  `SendOneTimeCodeJobTest`, `NodeTraceRecordingTest::test_what_a_contact_types_to_an_auth_request_never_reaches_the_trace`,
  `SystemStateNamespacePolicyTest`. Not covered: the contact's own reply stays in the conversation history (accepted, D8).
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
  waits for), `ask_person` (`system.ask_person.{node}.question_id`, the question it waits for), `auth_request` (its attempt and send counters, so no node of the flow's author can reset them);
  `rag.*`: `rag_query`. `flow.*` and `call.*` are open to
  every handler. A Solution's namespace (its id, `state: true`) is written only by node types with the same
  owner; no whitelist. Enforced: `SystemStateNamespacePolicy`, applied in `FlowSessionPersister`.
  Adding a writer of `system`/`rag` means editing the whitelist, on purpose.
- **A session runs the definition it started with.** `flow_definition_id` is set once, and
  definitions cannot be deleted while sessions reference them (`restrictOnDelete`).
- **A flow template never starts a session.** Every start path (default flow, trigger, global
  command, event job, `start_flow` job, subflow) loads its definition through
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
  `min(FlowEngineInterface::MAX_STEPS_PER_RUN, max_iterations × (max_loop_iterations + 1))`. Either kind keeps one budget
  per session per outermost engine call (`ExecutionSegment::stepBudget()`), so a parent resumed in a nested call inside
  its subflow step (its child ended in the same call) goes on from a count that holds that step — its loop's count, its
  steps and, in a conversation, its clock. A conversation counts per outermost call; an automation run counts over all its segments — steps outside loop bodies in `step_count` (capped by
  `max_iterations`), steps inside them in `loop_step_count` (the sum capped by the ceiling) — and a segment that starts
  inside a loop body knows it from the iterator in state. A conversation call, which has no segments, also fails a loop
  body still running after `FlowEngineInterface::MAX_SEGMENT_SECONDS` (30 s) of the outermost call — subflow children
  it ran synchronously included — so a long loop of slow nodes never outlives the 60 s job timeout and leaves the
  session stuck `active`. An automation also persists the steps of the iteration in progress (`loop_iteration_step_count`,
  reset by a `loop_end` and by the loop's exit), so a runaway inside or after a loop body that never reaches its
  `loop_end` stops at `max_iterations` steps of that iteration however many segments it spans, a body with a subflow in
  it included; a segment stops before it charges a step it will not run, so the shared budget never holds a step of a
  running session that nobody persisted. Settings are read through the scoped
  `PlatformSettingsReaderInterface`; a lost session lock aborts with `SessionLockLostException`. Why: a loop over a long
  list is legitimate and must not trip the shared budget, while a runaway flow must still free its worker
  (`adr-20261118-segmented-automation-runs`, amendment 2026-11-19). Enforced: `LoopEngineTest`, `AutomationRunTest`,
  `StepBudgetTest`, `FlowEngineTest`, `SubflowChildStopHandsBackTest`.
- **An automation run never outlives its job and runs once per fire.** One engine call of a `run_kind = automation`
  session — nested subflow and parent-resume calls included, sharing the scoped `ExecutionSegment` — stops before the
  next node after `flow.automation.segment_steps` or `flow.automation.segment_seconds` (whichever first, at least one
  step), and only the outermost call queues the continuation, after writing a new `segment_id` on the run's root
  session. A continuation runs only while the root carries its `segment_id`, and then runs the run's one `active`
  session (down the `paused_subflow` chain) — or, with none, hands a subflow child that is already over back to the
  parent still waiting on it (`SegmentHandOff::pendingReturnOf()`); a start runs only when `(trigger_id, fire_key)` has
  no run yet, and otherwise continues the run the same way (a `pending` root, which a schedule creates when it claims
  its slot, is activated first). Copies of a continuation of the same `segment_id` may be queued (the recovery pass);
  the run's lock serializes them and the first moves the run on. A run left without a job — no movement for
  `flow.automation.recover_after_minutes` (10) — is handed on by the recovery pass of
  `flow:sweep-overdue-automation-runs` (`StrandedAutomationRunRecovery`, after the deadline pass), which queues the
  root's current segment again (a first one for a root that has none) and runs no node (`adr-20261125-stranded-automation-run-recovery`). Jobs on `flow.automations` keep their timeout below the connection's
  `retry_after`, and the seconds setting's maximum keeps a segment plus the slowest node under that timeout. Why: a
  second runner or a re-reserved job repeats `call` side effects in somebody else's system, silently
  (`adr-20261118-segmented-automation-runs`). Enforced: `AutomationRunTest`, `AutomationRunLockRedisTest`,
  `ExecutionSegmentTest`, `HorizonAutomationsSupervisorTest`, `StrandedAutomationRunRecoveryTest`.
- **An automation run has a deadline frozen at its start; past it the whole run fails and runs no node.**
  `FlowEngine::start()` writes `deadline_at` = start + `flow.automation.max_run_hours` (24) for `run_kind = automation`
  only, and a subflow child copies its parent's; a row without one counts from `created_at`. Every minute
  `flow:sweep-overdue-automation-runs` (`AutomationRunDeadlineSweeper`, per tenant passed explicitly, stopped tenants
  skipped) fails every live session of a run past its deadline, or still `pending` 3 h after it was created, bottom-up in
  one transaction under the root's `LockScope::ofSession()`: `status = failed`, `failure_reason` `deadline_exceeded` or
  `start_lost`, the root's `segment_id` cleared, `updated_at` written; no node runs and no parent takes its `failed`
  handle. Conversations are never touched. A producer of `pending` rows must write their `deadline_at`. What counts as
  overdue is written once, in `AutomationRunDeadline` (`whereOverdue()` for the unlocked query, `isOverdue()` for the
  re-check under the lock, `effectiveFor()` for the deadline the warning logs); a change to the rule edits both forms
  there. Why: a run
  whose job was lost would keep its trigger and its flow for good, and a setting lowered later must not fail running
  runs at once (`adr-20261119-automation-run-deadline`). Enforced: `AutomationRunDeadlineSweeperTest` (including the
  agreement of the two forms at each threshold),
  `AutomationRunDeadlineSweeperRedisTest`, `SweepOverdueAutomationRunsCommandTest`, `AutomationRunTest`.

- **The node trace is always written, masked and limited before it is written, never inside a step's transaction,
  and read by screens for statuses, its data one visit at a time behind `ViewRunPayloads`.** `FlowEngine` calls `NodeTraceRecorder` for every node of every session: `node_entered`,
  then `node_completed` or `node_failed`, into `flow_session_history`, whatever `logging_enabled` (which still gates
  `state_change` and `subflow_*`). Only an automation run's rows carry `input`/`output`, and only while
  `flow.trace.payloads` is on: nothing deletes a conversation's history, so a conversation's trace keeps no payloads
  (gate decision of `flow-node-trace`, D-3). Payloads pass `TracePayloadMasker` (sensitive key names, also inside a
  JSON document held in a string; URL passwords and query parameters, also `query.<name>` call parameters and
  form-urlencoded bodies; literal secrets) and then `TracePayloadLimiter` (1 MiB a row); masking is a deny-list, so a secret
  under an innocent key in a response body stays visible — accepted risk, compensated by literal secrets once
  credentials pass them. A trace write failure is the warning `flow.trace.write_failed`, in a savepoint of its own,
  and never fails the step: the node's side effects have already happened. `FlowSessionInspector` filters
  `NodeTraceRecorder::EVENT_TYPES` out of the session's history. The run graph (`RunGraphReader`, behind
  `ViewFlowSessions`) reads the trace through a whitelist of envelope fields selected by JSON path — type, status,
  handle, next node, duration, loop iterations, error class — so `input`, `output` and the error message never leave
  the database through it, not even for a holder of `ViewRunPayloads` (spec `flow-automations`: `ViewFlowSessions`
  shows the graph and statuses only). Those are read only by `RunPayloadReader`, after `FlowSessionPolicy::viewPayloads()`,
  one trace row of a session of the run a request (`FlowSessionController::payload()`, throttled, `no-store`); a
  visit carries only its row's `id` and, for a holder, whether it has data (checked in SQL). Code that reads the trace
  for another screen keeps the same whitelist or goes through `RunPayloadReader` behind the same ability. A handler's
  `NodeExecutionResult::$trace` goes to the trace only. Enforced: `NodeTraceRecordingTest`, `CallNodeTraceTest`,
  `TracePayloadMaskerTest`, `TracePayloadLimiterTest`, `FlowSessionsConsoleTest`, `FlowRunGraphConsoleTest`
  (`adr-20261119-node-trace-in-session-history`).

- **A Core event starts each listening trigger's run once, and no chain of events is longer than five.** Core's events
  are the `CoreEventCatalog` names under the reserved `core.` prefix: validation refuses an `emit_event` with one
  (`reserved_event_name`), the handler drops one at runtime, and the registry never stores one. The publisher mints the
  event id before anything is queued and queues the fan-out after commit; a Core event's runs are automation runs with
  `fire_key = event_id`. An event raised while a session runs is one deeper than the session's `event_chain_depth`
  (written at start, copied to subflow children, pushed by `FlowEngine::executeLoop()` into the scoped
  `EventChainContext`); one deeper than `FlowTriggerEventPublisherInterface::MAX_CHAIN_DEPTH` starts nothing
  (`flow.event.chain_depth_exceeded`), on the contact path too. The Core domains raise plain events: the event classes
  reference nothing of Flow, Flow only listens, and `PublishCoreEvents` never throws into them. Conversation as a whole
  does not depend on Flow; Contact and Broadcasting import Flow elsewhere (`ContactCard`, `SendContactNotificationJob`,
  `SendBroadcastRecipientJob`), so for them this holds by review only. Why: a retried fan-out must not start a run
  twice, a flow must not fake a Core fact, and flows that set each other off must stop (`adr-20261119-core-events-start-contactless-runs`).
  Enforced: `CoreEventTriggersTest`, `QueuedFlowTriggerEventPublisherTest`, `PublishCoreEventsTest`,
  `ContactTagEventsTest`, `ValidateFlowServiceTest`, `BuiltInNodeHandlersTest`, `ConversationBoundariesTest`
  (Conversation only).
- **A registered trigger type starts contactless runs once per fire, only while its Solution is active, and a row of an
  unknown type never breaks a read.** Extensions add trigger types through Foundation's `TriggerTypeInterface`
  (`TriggerTypeRegistry`: classes built per call, owner = Solution id or null for Core, a Solution's type starts with
  `<id>.` and is declared in `extra.fapost.triggers`, a built-in name is never taken, frozen after boot). The five
  built-in types stay `FlowTriggerType`; `FlowTrigger::type` reads a built-in type as the enum and any other as its
  string, so code reads `typeName()`/`builtInType()`, never `->type->value`. `TriggerFirerInterface::fire()` refuses
  an unregistered type, an empty key or a payload over 64 KB at the caller, adds one to `EventChainContext` (past
  `MAX_CHAIN_DEPTH` nothing is queued, `flow.trigger.chain_depth_exceeded`) and queues `FireRegisteredTriggerJob`
  after commit; the job starts nothing while the owner is inactive, and otherwise one `StartAutomationRunJob` per
  active trigger whose config the type `matches()`, with fire key `tt:<key>` and state `flow.trigger.*` plus
  `flow.trigger_type`. Publish refuses `trigger_type_not_installed` / `unknown_trigger_type` and only warns for an
  inactive Solution; such a trigger gives no contact. Why: an extension's trigger must neither start a run twice nor
  run for a tenant that switched the Solution off, and a removed Solution must not take the flow list down with it
  (`adr-20261123-solution-trigger-types`). Enforced: `RegisteredTriggerTypesTest`, `RegisteredTriggerTypePublishTest`,
  `TriggerTypeRegistryTest`, `SolutionCoreRegistrarTest`, `FlowsConsoleTest`. *(proposed)*
- **An event trigger's `assistant_filter` matches the assistant that emitted the event, never the trigger's own, and a
  Core event takes none.** `config.assistant_filter` is a list of assistant ids; empty or absent is any assistant. A
  flow event passes when the assistant of its emitting session (`flow_sessions.assistant_id`, read by
  `ResolveEventTriggersService::forFlowEvent()` only when a trigger has a filter) is in the list; an unknown source
  passes only unfiltered triggers. Validation refuses a filter on a Core-event trigger
  (`assistant_filter_on_core_event`) and an id that is not an assistant of the tenant (`unknown_assistant_filter`).
  Why: two bots' events of one name must be separable without a naming convention, and a filter on an event that has
  no assistant would silently never fire (`adr-20261120-flow-event-trigger-assistant-filter`). Enforced:
  `DispatchFlowTriggerEventJobTest`, `BuilderApiTest`, `TriggerConfig.test.ts`.

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
- **Every payload a handler gives the flow `MessageSenderInterface` carries a non-empty `node_id`
  and an `idempotency_key` that is stable across a retry and unique per message.** The default is the
  node id and the step's `NodeExecutionContext::$idempotencyKey`; a message tied to a record uses the
  record (`ask_person`'s `question:{id}`), and a second message one step may send (a hint) gets a key
  of its own. `FlowMessageSender` keys the delivery `{session}:{node_id}:{idempotency_key}` and refuses
  a payload without them. Why: a missing key once crashed every input and `auth_request@2` prompt,
  and a default key would be worse — one key for the whole session, so `MessageSender` drops every
  later message as a duplicate. Enforced for the input and `auth_request@2` prompts:
  `FlowPromptDeliveryKeysTest` (engine, real `FlowMessageSender`).
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
  `SessionLockTimeoutException` and the queue retries (the automation jobs, `ResumeDelayedFlowSessionJob` and
  `ContinueAfterFlowQuestionJob` release themselves instead of spending an attempt, through the one policy in the trait
  `App\Jobs\Flow\ReleasesOnBusySessionLock`; each keeps its own `retryUntil()`, since each window starts elsewhere). A caller that may hold a contactless session takes its scope from `LockScope::ofSession()`, never
  builds it from the session's columns (callers that only ever hold a contact's session — buttons, operator requests,
  event starts, `start_flow` starts — still pass the triple). `DelayedSessionResumer`, `ResumeTimedOutSendMessageNodeJob`, `ContinueAfterOperatorRequestJob`,
  `FlowQuestionResumer` (`ContinueAfterFlowQuestionJob`),
  `StartFlowFromEventJob`, `StartFlowForContactJob`, `SubflowTimeoutSweeper`, `AutomationRunDeadlineSweeper`, `StrandedAutomationRunRecovery` and `AutomationRunner` follow it
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
  Enforced: `AutomationRunPrunerTest` (status × kind × end status × age matrix, enum completeness, `TERMINAL` equal to
  `FlowSessionStatus::terminal()`, chains, tenant),
  `PruneAutomationRunsCommandTest`.
- **A node that needs a contact is never published into a flow whose trigger gives none.** A handler declares
  `contact`, `none` or `any` through Foundation's `DeclaresContextRequirementInterface` (Solutions too); one that
  declares nothing needs a contact. Under a `schedule`, `webhook` or `api` trigger, or an `event` trigger on a Core event (`TriggerContactContext`), validate and publish refuse such a node, and
  any node whose config reads `contact.*` or `module.*` or uses a contact variable, with `node_needs_contact` at
  `nodes.<id>`. Declare `any` only for a handler checked never to use `NodeExecutionContext::$contactId`,
  `$platform` (both `null` there) or the contact writer without a contact, and hands them on only where the
  receiver takes `null` — `call` is `any` because `CallContext::$contactId` is nullable; `subflow` stays `contact`
  until its target is followed. A Core handler that needs a contact by type may name its recipient in a node's
  config instead (`App\Domains\Flow\Contracts\NamesOwnRecipientInterface`, Core-only): `send_message` with an
  `addressee` is then not refused for its type, though the rest of its config still is for contact reads; such a node
  never waits for an answer (`addressee_cannot_wait`) and never falls back to the run's contact when the addressee
  gives no id (`error`, `addressee_unresolved`). A flow is contactless when any of its triggers is, active or not.
  Why: a contactless run reaches a contact through an empty id or dies on a typed error nobody watches
  (`adr-20261118-node-contact-context-checked-at-publish`). Enforced: `ContactContextValidationTest`,
  `ContactlessFlowSessionTest` (the addressee), `BuiltInNodeHandlersTest`.
- **A press of a question's button is always the router's, and only the question's addressee decides it.** A
  `callback_query` whose text is `q1` + 32 hex + 2 hex (`Support/FlowQuestionButton`) is swallowed by `MessageRouter`
  (`flow_question_answer`) before the staff-ownership check and never reaches `FlowOrchestrator`, whether its token is
  known or not; `FlowQuestions::receivePress()` answers only for the question's contact, on its assistant, with an index
  of its buttons, and tells any other presser nothing. The question leaves `pending` by one conditional UPDATE; the run
  goes on only through `ContinueAfterFlowQuestionJob` under the run's own lock, never under the presser's. No other
  payload may start with `q1`: `send_message` and `input` drop an author's `callback_payload`. A button's id is kept
  as authored, since it becomes the branch's source handle and edges are matched exactly; two ids that differ only
  in case are refused as duplicates. Why: a press decides for a run
  with side effects elsewhere, and an answer to someone else's run must not become input to the presser's own
  conversation (`adr-20261123-ask-person-question-outside-the-run`). Enforced: `AskPersonNodeTest`,
  `MessageRouterTest`, `FlowQuestionResumerRedisTest`, `FlowTelegramMarkupCharacterizationTest` (`input`).
- **A message to tags or a segment is a broadcast, started once per node execution through Flow's port.**
  `send_message` with `addressee.kind` `tags` or `segment` (static values; `AddresseeResolver` reads every kind) calls
  `AudienceBroadcasterInterface`, implemented in `app/Infrastructure/Flow`, so the runtime never imports Broadcasting.
  It is text only, its `{{contact.*}}`/`{{module.*}}` are refused in every flow (`addressee_broadcast_contact_expression`),
  the broadcast is the flow's assistant's, its key is `sha256(idempotencyKey:nodeId)` and no `system.*` marker, it
  never waits for the broadcast, and every refusal or failure takes `error` without throwing. The broadcast's completion
  event is published at the run's chain depth + 1 (`broadcasts.chain_depth`, `PublishCoreEvents` sets it on
  `EventChainContext`), so the chain limit stops flows that broadcast on each other's broadcasts
  (`adr-20261123-flow-audience-message-through-broadcasting`). Enforced: `SendMessageBroadcastTest`,
  `BuiltInNodeHandlersTest`, `PublishCoreEventsTest`.
- **`notify`'s `contacts` mode is retired: never added or changed at publish, and a published one sends only as a
  broadcast.** Publishing a flow's or a template's draft that adds such a node or changes it against the published
  version (mode, assistant, audience, tags, message, after template expansion) is refused with `notify_contacts_retired`;
  an unchanged one only warns (`notify_contacts_deprecated`), and a template republishing its hosts is not checked. At
  run time the node starts a broadcast of its own `assistant_id` through `AudienceBroadcasterInterface` (tags, or
  everyone — an audience only this node may name), with `send_message`'s key, live limit and chain depth, never
  dispatches `SendContactNotificationJob`, refuses a text reading `contact.*`/`module.*`, keeps its
  `system.contacts_notified.<node>` marker and always leaves through `default`, the outcome in `broadcast_outcome`
  (`adr-20261125-notify-contacts-retired-through-broadcasting`). Enforced: `NotifyNodeHandlerTest`,
  `NotifyContactsBroadcastTest`, `NotifyContactsPublishTest`.
- **A flow started for a contact starts once per step, never for the run's contact, and never over a moving
  conversation or an operator.** A `start_flow` node's start key is the engine's execution key and the node id,
  written on the started session (`flow_sessions.start_key`, unique); the node answers `started` when a session has
  its key, and `StartFlowForContactJob` starts nothing for a used key, under the contact's lock and through the unique
  violation of a racing duplicate. The contact comes only from the node's addressee (`addressee_unresolved`
  otherwise); the conversation is on the target flow's draft assistant, and a contact with no channel on its active
  channel is `no_channel`, never an exception. Busy (nothing sent) is: the delivery thread `staff` or `queued`, a live
  conversation with that bot that moved within `busy_window_minutes` or sleeps in a `delay`, or the asking session or a parent of it being
  that conversation; an older live conversation is ended by `FlowSessionTerminator` before the start. The node's
  check is advisory, the job's under the lock holds. The started session is one event-chain level deeper than the run
  (`chain_depth_exceeded` past `MAX_CHAIN_DEPTH`). Why: the person did not ask for the message, a sent message cannot
  be taken back, and a repeat would write to them twice (`adr-20261123-start-flow-for-contact`). A flow event's start
  (`StartFlowFromEventJob`) keeps the same rules on the trigger's assistant: its start key is `event:` + a hash of the
  trigger id and the event id (else the queue job's uuid), the emitting session (`source.session_id`, passed by
  `DispatchFlowTriggerEventJob`) is the asking one, and the window is fixed at
  `ContactFlowStartCollision::DEFAULT_BUSY_WINDOW_MINUTES` (`adr-20261123-event-start-collisions`). Enforced:
  `StartFlowForContactTest`, `ContactContextValidationTest`, `StartFlowFromEventJobTest`.
- **A trigger goes live only on publish, and its id is its row's primary key.** A flow's triggers are a list
  `{id, type, is_active, priority, config}` in `flow_drafts.triggers` and `flow_definitions.triggers`; `null` there
  means "not written yet", and the flow's live `flow_triggers` rows count (`FlowTriggerSet`), `[]` means none. A draft
  save writes only the list; `PublishFlowService` validates every trigger and syncs the rows by id
  (`FlowTriggerRepository::syncForFlow`), keeping `last_run_at`/`next_run_at` of a trigger whose type and config did
  not change (compared ignoring key order: jsonb reorders keys) and resetting `next_run_at` when they did (an active
  schedule then gets its first slot after now). A republish by a template takes the list from the host's active
  definition, never its draft, and a `null` list leaves the rows alone. Nothing else writes a trigger's definition
  columns; its run state (`next_run_at`, `last_run_at`) is written only by this sync, by `ScheduleTriggerFirer`'s slot
  claim and by flow deletion, which clears a schedule's slot — all through `FlowTriggerRepositoryInterface`
  (`syncForFlow`, `moveScheduleSlot`, `stopSchedules`; the due and next-run reads are `dueSchedules`, `nextScheduleRuns`),
  and the comparison and by-id diff of trigger lists live in `Support/FlowTriggerComparison`, which also declares the
  `Trigger` shape (`adr-20261123-flow-schedule-trigger`). Flow deletion also
  cancels the flow's root `pending` runs (a schedule's, not started yet) under their optimistic lock in its transaction,
  so a frequent schedule never makes its flow undeletable; a run that has started still refuses the delete. Why: a draft edit must not change what runs, and a live
  trigger must survive the release that introduced the list (`adr-20261119-flow-triggers-in-definition-live-on-publish`).
  Enforced: `FlowTriggersPublishTest`, `SaveDraftServiceTest`, `FlowTemplatePublishTest`, `FlowTriggerSetTest`.
- **An automatic trigger or a new credential reference goes live only with its own right.** Publishing a draft that
  adds, removes or changes (type, config, `is_active`, `priority`) a trigger other than `message` against the live
  rows needs `ManageAutomations`; publishing a `call` node bound to a saved credential the published definition does
  not reference needs `UseCredentials` (dropping one needs nothing); adding a `send_message` to tags or a segment, or
  changing its audience, text or name, needs `ManageBroadcast`. `FlowPublishChanges` computes all three,
  `FlowDraftPolicy::publishChanges()` refuses with a translated reason, asked by the builder's publish after
  `publish`; credentials and triggers are compared on the draft before template expansion, so a credential a template
  brings is checked when the template is published, while audience messages are compared after expansion (a template
  can bring one, and a host's parameter value rewrites its text; copies keep stable ids). The `ManageBroadcast` check covers the audience node's own fields only: what its `{{flow.*}}` placeholders read upstream and the graph leading to it (edges, loops, `call` nodes) are not compared, so a publisher with `PublishFlow` alone can still change an approved broadcast's content and frequency that way; extending the right is an open owner decision. Turning on a flow with a live automatic trigger needs `ManageAutomations`
  (`FlowDraftPolicy::activate()`), since its automatic triggers start runs only while it is on; turning it off does not; the builder's credential list and a call test with a
  credential need `UseCredentials` (`CredentialPolicy::use()`). Saving a draft checks neither: nothing saved runs.
  Why: `PublishFlow` alone would let an author start runs nobody asked for or spend a secret an administrator
  created for someone else. Enforced: `FlowAutomationPermissionsTest`, `BuilderAuthorizationTest`,
  `CredentialOptionsEndpointTest`.
- **A webhook trigger's secret never enters its config, and its URL starts a run only while someone can revoke it.**
  The secret lives encrypted in `flow_webhook_endpoints` (one row per trigger id, bound to its `flow_id`), is shown only
  in the answer that generated it, and never reaches the draft, a definition or a template: `WebhookTriggerConfig`
  refuses any key it does not know and a draft save drops `secret`. `FlowWebhookReceiver` serves a token only for a
  live, active webhook row of the endpoint's flow whose flow has an active runnable definition and still has its draft
  and assistant, and answers 404 after the signature when the flow is switched off in the flows list (the delivery not
  claimed, the address not counted); a timestamp check is accepted only inside the signed string. The flow never sees the request's
  credentials, the signature or secret header, the signed timestamp or any header named `*signature*`
  (`WebhookTriggerConfig::isStrippedHeader()`), and a `delivery_id` may not name one of them: the id is logged, kept in
  the run's state and fire key, and sent back to the sender. A publish that deletes a live webhook
  trigger or changes its type deletes its endpoint, and deleting a flow deletes its endpoints. A delivery runs once:
  Redis `SET NX` per `(tenant, trigger, fire key)` for a day, then the session's unique `(trigger_id, fire_key)`. Why:
  the config is copied into every version and template, and a URL held by an outside system must stop working when its
  flow cannot be managed (`adr-20261119-flow-webhook-trigger`). Enforced: `FlowWebhookTriggerTest`,
  `FlowWebhookEndpointTest`, `FlowWebhookHostModeTest`, `WebhookTriggerConfigTest`, `RedisWebhookDeliveryDedupRedisTest`.
- **An API trigger starts a run only for a token holding `StartFlowRuns`, only while its flow can be managed, and once
  per key and token.** `POST /api/v1/flow-triggers/{trigger}/runs` runs on the `api-token` stack (tenant of the host,
  token, its rate limit, a stopped tenant's write refusal); `FlowApiTriggerController` asks
  `Gate::allows(Permission::StartFlowRuns->value)`, never a policy (`adr-20261119-tenant-api-tokens` §7), and hands
  only the token's id and name to Flow. `FlowApiTriggerReceiver` serves a trigger only through `LiveTriggerLookup` —
  the same check the webhook uses: a live, active row of the tenant and of the asked type whose flow has an active
  runnable definition and still has its draft and assistant — and answers 404 for every other reason, a row whose
  config `ApiTriggerConfig` refuses (the old `route_key`/`allowed_sources`) included. A flow switched off in the flows list answers
  404 too, before the paused check and the claim. The config is `{response_mode}`
  only. The fire key is `api:<token id>:<Idempotency-Key>` (hashed when too long), so a key dedups per token, or
  `api:<ULID>` without a key; dedup, the paused-trigger 503 before the claim and the `sync` wait are the webhook's
  (`WebhookDeliveryDedupInterface`, `WebhookSyncWait`). The run's state is `flow.api` = body, request id, token id
  and name, received at — never a header. A run whose fire key is `wh:` or `api:` (`InboundFireKey`) is the one a
  `webhook_response` node or the run's end answers. Why: a token reaches every assistant of the tenant, and the
  call's log is the run itself (§12 of the ADR). Enforced: `FlowApiTriggerTest`, `ApiTriggerConfigTest`,
  `WebhookRunReplyTest`, `FlowTriggersPublishTest`.
- **A run is re-run from the start only through its trigger's start job, with the payload it kept.** `FlowEngine::start()`
  writes `flow_sessions.trigger_payload` = the `initialState` as handed over, only for a start with a fire key (a root
  automation run); conversations and subflow children keep NULL. `AutomationRunRerunService` re-runs only a root
  automation run in status `failed` with a trigger, a fire key and a kept payload, while its trigger row is active and
  of the same flow and tenant and not paused by the run rate breaker (`trigger_paused`), the flow's draft exists and is
  not switched off (`flow_off`) and the flow has an active version; anything else is a
  `RerunRefusal`, never an exception. It queues `StartAutomationRunJob` with the kept payload (never rebuilt from the
  trace or the final state), the source's tenant, assistant and `event_chain_depth`, under the fire key
  `rerun:<source id>:<n>` (`RerunFireKey`; `n` is the attempt the page offered — the highest attempt among the source's re-runs with a row + 1, never their count —
  and a request whose `n` is no longer the next one is refused as `already_rerun`), and records `flow.run_rerun` in the
  audit log first. The source run is never changed. Why: the trace is masked and cut, the state is the flow's to
  overwrite, a start outside the job would skip the trigger's lock and queue, and a deterministic key makes a repeated
  request one run while never matching a webhook's `wh:` key, so no sender is answered
  (`adr-20261123-rerun-from-start-keeps-trigger-payload`). Enforced: `AutomationRunRerunTest`,
  `FlowSessionRerunConsoleTest`, `RerunFireKeyTest`.
- **A schedule claims its slot in the tenant, creates its run `pending` in the same transaction, and never catches
  up.** `ScheduleTriggerFirer` moves `flow_triggers.next_run_at` from the slot it read to the next one by compare-and-set
  and, in the same transaction, creates the run `pending` (`FlowEngineInterface::prepare()`, fire key
  `schedule:<UTC minute>`, deadline written) or skips the slot: the flow's draft or the draft's assistant is gone
  (`tick_skipped` `flow_not_live`, and the row moves to no slot: nobody is left to switch the schedule off), more than
  5 minutes late (`flow.schedule.tick_missed`, the next slot counted from now), no active runnable definition
  (`tick_skipped` `flow_not_live`, the next slot kept), the flow is switched off in the flows list (`tick_skipped`
  `flow_off`, logged at info: the owner chose it), or a previous root run of the
  trigger is `pending` or in flight (`tick_skipped` `previous_run_live`), or run admission refuses it (`tick_skipped`
  `admission_refused`, `adr-20261123-flow-run-admission`). The start job is queued after the commit and
  `AutomationRunner` activates the `pending` run (`FlowEngineInterface::activate()`). A pass runs at most one slot of a
  trigger, the earliest due; the later slots a pass within the 5 minutes passed over are logged as one `tick_missed`
  with `missed_slots` — such lines without a downtime are the signal that the pass outgrows the minute. A rebuild of a
  tenant's registry rows changes or deletes only rows written before it read the tenant, so a publish's row written
  meanwhile survives it. `ScheduleClock` skips a local time a
  daylight saving change removes and fires a repeated one once; a cron is five valid fields or a macro (`@hourly`, `@daily`, ...) in an IANA zone
  (`invalid_schedule_cron`, `invalid_schedule_timezone`). The landlord `flow_schedule_registry` is only an index of whom
  to visit, written after the tenant's commit through `ScheduleRegistrySync` and rebuilt hourly
  (`flow:rebuild-schedule-registry`); `flow:fire-scheduled-triggers` enters only the tenants it names, never a stopped
  one. Why: no transaction spans landlord and a tenant schema, and a slot eaten without a run is a silent loss
  (`adr-20261123-flow-schedule-trigger`). Enforced: `ScheduleTriggerFirerTest`, `ScheduleClockTest`,
  `FireScheduledTriggersCommandTest`, `FlowTriggersPublishTest`.
- **A flow switched off in the flows list starts no automatic run, and nothing is caught up when it is turned on.**
  Every automatic start path asks `FlowDraftRepositoryInterface::isSwitchedOff()` (the draft exists and `is_active` is
  false) after deduplication and before run admission: `AutomationRunner::start()` after `findByFireKey` (a fire that
  already has a run, a schedule's `pending` one included, goes on; `flow.automation.start_skipped_flow_off`),
  `ScheduleTriggerFirer`'s claim (the slot is spent, `tick_skipped` `flow_off`), `StartFlowFromEventJob` after its start
  key and before its channel and collision checks (`flow.event.start_skipped_flow_off`), `FlowWebhookReceiver` after the signature (404, the delivery not claimed, the address not counted),
  `FlowApiTriggerReceiver` before the paused check (404, the key not claimed) and
  `AutomationRunRerunService::check()` (`RerunRefusal::FlowOff`). A new automatic start path asks it too. Run admission
  is not asked for a switched-off flow, so it spends no unit and counts no refusal. Conversations (message triggers,
  `/start`, the default flow, subflows, `start_flow`) do not read the switch. Why: the switch and its
  `ManageAutomations` check must mean what they say, without a refusal counted every minute on a flow a person turned
  off (`adr-20261123-flow-switch-gates-automatic-starts`). Enforced: `FlowSwitchGatesAutomaticStartsTest`,
  `ScheduleTriggerFirerTest`, `FlowWebhookTriggerTest`, `FlowApiTriggerTest`, `RegisteredTriggerTypesTest`, `AutomationRunRerunTest`,
  `MessageRouterPipelineTest` (a message still starts). Not covered: that a future start path asks (review only).
  *(proposed)*
- **A trigger's new run passes run admission once, before it exists; a pause is lifted only by a person.** Every
  automatic start — `AutomationRunner::start()` for a fire with no run yet (webhook, Core event, a registered trigger
  type, a re-run), `StartFlowFromEventJob`, and
  `ScheduleTriggerFirer`'s claim before `prepare()` — asks `TriggerRunAdmission` in this order: a paused trigger
  (`trigger_paused`), the run rate breaker (`flow.automations.runs_per_trigger_per_minute` runs a minute per tenant and
  trigger; over it `rate_breaker` and the trigger is paused), the plan's `flow_runs` through `PeriodQuota`
  (`plan_limit`). A retry or continuation of a fire that has a run is never asked; a message trigger never is.
  `StartFlowFromEventJob` asks under the contact's lock, after its start key, channel and collision checks, so a start
  skipped for a busy or unreachable contact is never metered (`adr-20261123-event-start-collisions`), and through
  `admitOnce()`: an allowed start is remembered by tenant and unit key for an hour, so the job's retry after a failure
  that followed it (ending a stale conversation, the engine's start) is not counted by the breaker again. The
  meter's unit key is a hash of the trigger and what fired it, never the fire key. A refusal starts nothing and is
  counted on `flow_trigger_states`; the pause lives there too, written only through
  `FlowTriggerStateRepositoryInterface`, never on `flow_triggers`, and a publish never lifts it. A webhook to a paused
  trigger answers 503 before its delivery is claimed. Why: a looping or runaway automation must stop on its own without
  changing what was published, and the plan must count each run once (`adr-20261123-flow-run-admission`). Enforced:
  `TriggerRunAdmissionTest`, `TriggerResumeEndpointTest`, `FlowWebhookTriggerTest::test_a_paused_trigger_answers_503_with_retry_after_and_keeps_the_delivery_unclaimed`.
  `TriggerRunAdmissionTest::test_a_message_trigger_never_asks_admission` covers the message router's start.
- **A webhook request or an API call waits for its flow only while it holds a wait slot, and never past 30 seconds.** A
  webhook or API trigger in
  `response_mode: sync` holds the request through `WebhookSyncWait`, which first takes a slot of
  `WebhookWaitSlotsInterface` (`flow.webhook.sync_waiters_max` on the installation, `flow.webhook.sync_waiters_per_tenant`
  per tenant, both at once or neither) and gives it back in `finally`; no slot, no answer in time or a Redis failure
  answers 202. A repeated delivery never waits. The answer is the first one stored for the run (`SET NX`): a
  `webhook_response` node, else the run's end through `WebhookRunEndReply`, which never fails the engine's caller.
  Why: every waiting request holds a PHP-FPM worker, and an unbounded wait would take the console of every tenant
  down with it (`adr-20261119-flow-webhook-trigger`). Enforced: `FlowWebhookTriggerTest`, `WebhookRunReplyTest`,
  `FlowWebhookSyncReplyRedisTest` (the receiver's wait and the run's answer meet on one key), `RedisWebhookWaitSlotsRedisTest`,
  `RedisWebhookReplyStoreRedisTest`, `PlatformSettingsTest` (the 30-second ceiling).
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
