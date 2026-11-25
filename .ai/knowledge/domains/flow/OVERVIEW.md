---
id: domain-flow
type: domain
status: active
summary: "Flow runtime: routing, engine, handlers, state, session lock, subflows, triggers, translations"
domains:
  - flow
topics: []
load: domain
paths:
  - "app/Domains/Flow/**"
  - "app/Infrastructure/Flow/**"
  - "app/Jobs/Flow/**"
  - "app/Console/Commands/Flow/**"
  - config/flow.php
  - "tests/*/Domains/Flow/**"
  - tests/Architecture/FlowRuntimeIsolationTest.php
  - tests/Architecture/HandlerVersionContractTest.php
  - "database/migrations/tenant/*flow_*"
  - "database/migrations/tenant/*_variable_schema*"
  - "database/migrations/tenant/*persistent_inline_buttons*"
  - "database/migrations/tenant/*tenant_events*"
  - "database/migrations/tenant/*tenant_translations*"
  - app/Console/Commands/CreateNextFlowLogPartitionCommand.php
  - app/Console/Commands/PruneFlowLogsCommand.php
  - "app/Domains/Flow/Templates/**"
reviewed_at: 2026-11-25
---
# Flow

## Responsibility

Flow executes published flow definitions for a contact, or as a contactless automation run
(no contact, optionally no assistant; started through `Orchestration/AutomationRunner` by `StartAutomationRunJob`, which a Core event dispatches — `DispatchFlowTriggerEventJob`, from the events of `Events/CoreEventCatalog` that `Listeners/PublishCoreEvents` publishes — the webhook and API triggers dispatch, and the schedule trigger dispatches for the `pending` run it created when it claimed a slot — `Schedule/ScheduleTriggerFirer`, run by `flow:fire-scheduled-triggers`). It routes an inbound message or event
to a flow session, runs node handlers until the flow waits, ends or fails, and persists session
state. It also owns triggers, subflows, the session lock, run history and logs, the template
expression engine, and content translation (`Translations/`, `ContentTranslatorInterface`),
which other domains reuse.

Authoring — drafts, validation, publishing and the visual editor — is described in domain
`flow-builder`.

## Sub-areas

| Concern | Where |
|---|---|
| Runtime | `Services/FlowEngine.php`, `Orchestration/FlowOrchestrator.php`, `Registry/`, `Handlers/`, `Services/FlowSessionPersister.php`, `Services/FlowGraphResolver.php` |
| Inbound routing | `Routing/MessageRouter.php` (commands, staff-takeover check, typing indicator, routing decision), `Routing/SessionStateRouter.php`, `Commands/` |
| Concurrency | `Concurrency/` (Redis session lock and heartbeat), `app/Infrastructure/Flow/FlowExecutionGuard.php` |
| State | `State/` (`ScopedStateReader`, `Writers/ContactWriter`, `SystemStateNamespacePolicy`, `SystemStateKeys`, `Variables/`) |
| Subflow | `Subflow/` (starter, resumer, call-graph validator, timeout sweeper) |
| Flow templates | `Templates/` (`TemplateExpander` copies a template's active definition into a host at publish, `TemplateUsageRepository` over `flow_template_usages`, `TemplateUsageFinder`, `TemplateNodeOrigins` labels copied nodes by `origin` on the session and log screens); handlers `TemplateNodeHandler`, `TemplateOutputNodeHandler` are pass-throughs (`adr-20261111-flow-templates-expanded-at-publish`) |
| Triggers and events | `Services/Resolvers/`, `Services/FlowTriggerSet` (a flow's trigger list; synced into `flow_triggers` on publish), `ResolveEventTriggersService`, `Events/`, `app/Jobs/Flow/` |
| Publish rights | `Services/FlowPublishChanges` (what a publish changes that needs a right beyond `PublishFlow`), `Support/AudienceMessageCone` (the cone of a broadcast compared at publish); asked by `Policies/FlowDraftPolicy::publishChanges()` |
| Call, RAG, Action, Expression | `Call/`, `Rag/`, `Action/`, `Expression/` — small registries |
| One-time codes | `Auth/` (`AuthCodeStore`: the keyed hash of a pending code and the per-destination cap; `OneTimeCodeSenderRegistry`, Core's `EmailOneTimeCodeSender` and `OneTimeCodeMail`), `app/Jobs/Flow/SendOneTimeCodeJob.php` — used by `auth_request@2` |
| History and logging | `History/`, `Logging/` (partitioned `flow_logs`), `Trace/` (node trace in `flow_session_history`) |
| Translations | `Translations/`, `app/Infrastructure/Flow/CachedContentTranslator.php` |
| Node usage statistics | `Statistics/NodeUsageStatisticsService` behind `Contracts/NodeUsageStatisticsInterface`; command `app/Console/Commands/Flow/NodeUsageCommand.php` (`flow:node-usage`), run on demand, not scheduled |

## Boundaries

- The handler contract and the canonical state namespaces live in the foundation package:
  `Fapost\Foundation\Contracts\NodeHandlerInterface`, `Fapost\Foundation\Flow\Enums\StateNamespace`.
  State is read by `ScopedStateReader` and written through `stateChanges` → `FlowSessionPersister`
  and `ContactWriter`; there is no second state layer in Core (ADR-0002).
- Flow depends on other domains mostly through their concrete models: `Contact` and
  `ChannelContact` (handlers query them; `ContactWriter` saves `Contact`), `Assistant`, `Channel`,
  `MediaFile`. Contracts it uses: `ContactServiceInterface`, `ContactTagRepositoryInterface`, the
  Media dispatcher/ingestor/service, Conversation ownership and logger, `CurrentAssistantInterface`,
  and the foundation `MessageSenderInterface`.
- Consumers: Webhook (`MessageRouter`, `RoutingOutcome`), Media (a `FlowDefinition::saved` hook
  for reference tracking), Staff (`FlowSession` in the notification job), Contact and Broadcasting
  (`ContentTranslatorInterface`). Contact↔Flow is an import cycle through Contact's deprecated
  `SendContactNotificationJob`, which imports Flow; `NotifyNodeHandler` no longer dispatches it (its retired
  `contacts` mode starts a broadcast through `AudienceBroadcasterInterface`), and it goes with the cycle a release later
  (`adr-20261125-notify-contacts-retired-through-broadcasting`).

## Entry points

- Inbound: Webhook's `IncomingMessageJob` (queue `flow.execution`) → `MessageRouter::route()` →
  `FlowOrchestrator::handle()` → `FlowEngine::start()` / `resume()`.
- No active session: `FlowOrchestrator::handle()` takes the resolved trigger's flow, else the assistant's
  `default_flow_id`, loads it through `findLatestActiveByFlowId` and calls `FlowEngine::start()` when the access
  policy lets the contact start it; otherwise it sends the assistant's `fallback_message` through
  `FallbackMessageService` and stops.
- `MessageRouter::route()` order: (1) global command match (`/reset`, `/cancel`, tenant
  `assistant.commands`) without the lock; (2) typing indicator start; (3) session lock
  `session_lock:{tenant}:{contact}:{assistant}` (`Concurrency/LockScope`, Redis, 30 s TTL) taken through
  `LockAcquisitionPolicy` (3 attempts, 2 s apart) and published to `SessionLockRegistry`, so
  `FlowExecutionGuard` stays re-entrant and the engine extends the TTL before each node; (4) a press of an
  operator request's cancel button (`operator_request_cancel` drop, `Support/OperatorRequestCancelButton`), a press of an
  `ask_person` question's button (`flow_question_answer` drop, `Support/FlowQuestionButton`), then the staff
  ownership check (`staff_handled` drop, before session classification, because the session may still
  be in `waiting_input` from before the takeover), then session classification by `SessionStateRouter`;
  (5) `FlowOrchestrator::handle()`; (6) cleanup. A lock miss sends the busy reply and returns
  `RoutingOutcome::dropped('lock_timeout')`; `engine_lock_timeout` and `lock_lost` come from the
  orchestrator. Entry points that bypass the router (`DelayedSessionResumer`,
  `ResumeTimedOutSendMessageNodeJob`, `StartFlowFromEventJob`, `StartFlowForContactJob`) take the same lock through
  `FlowExecutionGuard::run()` / `runInScope()`; a contactless automation run takes its trigger's lock
  (`LockScope::ofSession()`).
- Automation runs: `AutomationRunner` starts a run once per `(trigger_id, fire_key)` under the trigger lock and runs
  it in execution segments (`Services/ExecutionSegment`); a segment that runs out is handed on by
  `Services/SegmentHandOff` (a new `segment_id` on the run's root, then `ContinueAutomationRunJob` on
  `flow.automations`) (`adr-20261118-segmented-automation-runs`). A failed root run is re-run from the start by
  `Services/AutomationRunRerunService` from the sessions screen: the trigger payload kept on the row, the same start
  job, a `rerun:` fire key (`Orchestration/RerunFireKey`) (`adr-20261123-rerun-from-start-keeps-trigger-payload`).
  `flow.automations`) (`adr-20261118-segmented-automation-runs`).
- Starting a flow for a contact: the `start_flow` node (`Handlers/StartFlowNodeHandler`) checks the addressee, the
  target's active definition, the contact's channel on the target's bot (`Services/ContactChannelProbe`), the chain
  depth and the collision (`Services/ContactFlowStartCollision`), then queues `StartFlowForContactJob`
  (`scheduled.triggers`) through `ContactFlowStartDispatcherInterface`; the job re-checks under the contact's lock,
  ends stale conversations (`Services/FlowSessionTerminator`, shared with the global commands) and calls
  `FlowEngine::start()` with a unique `start_key` (`adr-20261123-start-flow-for-contact`). That sequence under the
  lock is `Services/ContactFlowStarter`, shared with `StartFlowFromEventJob` (which adds run admission as a hook
  after the collision check); the jobs keep their own lookups and log lines.
- Engine navigation: `FlowSessionPersister::persist()` maps results to session columns (`Executed` →
  next node or completed, `Waiting` → `waiting_input`, `Delayed` → `paused` when it carries `resumeAt`, else `waiting_input`, `Failed`, `Finished`), `persistEnd()` closes
  the session with an `end_status`; all writes use the optimistic lock on `flow_sessions.version`.
  A `loop_end` node navigates back to its `loop_node_id` without a graph edge. A subflow node pauses
  the parent as `paused_subflow` (`Subflow/SubflowStarterService`) and runs the child synchronously
  through `runSession()`; when the child ends before the node's own result is persisted, the engine
  skips persisting the stale `Waiting` result (skipPersist). `resumeAfterSubflow()` advances the
  parent, `resumeFromNode()` creates a session at the node after a given handle, and
  `runSession($session, resumedAfterDelay)` re-enters the loop for a woken session.
- Operator requests: the `request_operator` node places a request through Conversation's
  `OperatorRequestsInterface` and waits; `ExpireOperatorRequestJob` (`flow.execution`, scheduled through
  `OperatorRequestTimeoutSchedulerInterface` → `DeferredResumeDispatcher`) times it out; Flow's
  `Listeners/ContinueAfterOperatorRequest` queues `ContinueAfterOperatorRequestJob` on every
  `OperatorRequestStateChanged`, which under the session lock removes the cancel button, sends the stored text and
  resumes the session through `resume()` with a wake-up message while it still waits in that node. Commands that end a
  session cancel its pending request (`GlobalCommandExecutor`).
- Questions: the `ask_person` node records a question in `flow_questions` (`FlowQuestionsInterface`), sends it with
  `q1…` button payloads and parks `paused` until its `expires_at` (`delayed(resumeAt)`, capped a minute before an
  automation's deadline). `MessageRouter` swallows every such press after the operator-cancel button
  (`FlowQuestions::receivePress()`); an accepted answer queues `ContinueAfterFlowQuestionJob` (`flow.execution`) →
  `Orchestration/FlowQuestionResumer`, which runs the session under `LockScope::ofSession()` while it still waits on
  that question; the timeout is the ordinary delayed wake-up (`adr-20261123-ask-person-question-outside-the-run`).
- Flow templates: a `FlowDraft` with `kind = template` is never started (the definition repository's
  `runnable()`); publishing a host expands each `template` node into a copy that runs in the host's
  session, and publishing a template republishes every host from its active definition in the same
  transaction (`PublishFlowService`), refusing the publish if any host would break. A host's
  parameter values (`config.parameter_values` on the reference) replace the fields the template
  exposes, in the copy (`adr-20261111-flow-template-parameters`).
- Webhook triggers: `POST /hooks/flow/{token}` on the tenant's host (`routes/flow-webhook.php`, no `web` group) →
  `Http/Controllers/Flow/FlowWebhookController` → `Webhook/FlowWebhookReceiver` (endpoint by token, live trigger,
  limits, Foundation `IngressSpecExecutor`, dedup) → `StartAutomationRunJob` with the request under `flow.webhook`, 202;
  in `response_mode: sync` the receiver then waits through `Webhook/WebhookSyncWait` (wait slots, `BLPOP` on the reply
  store) for the `webhook_response` node's answer or the run's end (`Webhook/WebhookRunEndReply`, called by `FlowEngine`).
  The builder makes the URL and the secret through `Webhook/FlowWebhookEndpointService`
  (`adr-20261119-flow-webhook-trigger`).
- API triggers: `POST /api/v1/flow-triggers/{trigger}/runs` (`routes/api.php`, the `api-token` stack) →
  `Http/Controllers/Flow/FlowApiTriggerController` (`StartFlowRuns` by value) → `Api/FlowApiTriggerReceiver` (JSON
  object, `Services/LiveTriggerLookup` — shared with the webhook — `Api/ApiFireKey`) → `Webhook/InboundRunStarter`
  (the tail both inbound receivers share: dedup claim, kept answer of a duplicate, dispatch, `sync` wait) →
  `StartAutomationRunJob` with the call under `flow.api`, 202; `response_mode: sync` waits through
  `Webhook/WebhookSyncWait` like a webhook (`Webhook/InboundFireKey` tells both kinds of run apart).
- Events: `StartFlowFromEventJob` (queue `scheduled.triggers`) and `DispatchFlowTriggerEventJob`; a flow event's start
  takes the `start_flow` collision and once-only rules (`event:` start key, the emitting session as the asking one,
  `adr-20261123-event-start-collisions`). Schedules:
  `Schedule/ScheduleTriggerFirer` claims due slots (run by `flow:fire-scheduled-triggers`), `Schedule/ScheduleClock`
  computes slots, `Schedule/ScheduleRegistrySync` copies `next_run_at` to the landlord registry after commit; the runs
  start through `StartAutomationRunJob` on `flow.automations`. Timeouts: `ResumeTimedOutSendMessageNodeJob`.
- Delays: the `delay` handler schedules `ResumeDelayedFlowSessionJob` (queue `flow.execution`)
  through `DelayResumeSchedulerInterface` → `DelayedSessionResumer`, which takes the session lock
  and calls `FlowEngine::runSession()`. The node is clock-gated, so an inbound message after
  `resume_at` also moves it on; the session parks in `waiting_input`, not `paused` —
  `findActiveForContact()` does return `paused` sessions too (next bullet), but the `delay` node's
  own marker (`system.delay.{nodeId}`) is unrelated to that status.
- `NodeExecutionResult::delayed()` (Foundation) has two forms. Without `resumeAt` it parks the
  session in `waiting_input` exactly like `waiting()`: the next inbound message re-runs the node.
  With `resumeAt` the engine — not the handler — parks the session on `paused` and writes
  `system.delayed.{nodeId}.resume_at` (`FlowSessionPersister`), then schedules the wake-up through
  the same `DelayResumeSchedulerInterface` → `DelayedSessionResumer` path as the `delay` node;
  `DelayedSessionResumer` tells the two forms apart by marker prefix and status, and runs the
  woken node with `NodeExecutionContext::$resumedAfterDelay = true`. `findActiveForContact()`
  returns `paused` sessions so the routing pipeline can see them: `MessageRouter` answers busy
  while `resume_at` is still in the future, and wakes the node inline
  (`DelayedSessionResumer::wakeIfDue()`, under the lock it already holds) before routing the
  message once `resume_at` has passed — a message during the pause is never queued for the node,
  only the busy reply is sent. `GlobalCommandExecutor` (`/reset`) can terminate a `paused` session
  the same way it terminates any other active one.
- `send_message` timeouts: the handler schedules `ResumeTimedOutSendMessageNodeJob` (queue
  `flow.execution`) through `SendMessageTimeoutSchedulerInterface`; the job resumes the node on its
  `no_response` handle under the session lock. Both deferred resumes go through
  `app/Infrastructure/Flow/DeferredResumeDispatcher`, which queues nothing on a `sync` queue: it
  cannot delay, and would re-enter the running engine. There a timeout never fires and a delay
  moves on only with the next inbound message.
- Console: `flow:sweep-subflow-timeouts`, `flow:sweep-overdue-automation-runs` and `flow:fire-scheduled-triggers` (every minute), `flow:rebuild-schedule-registry` (hourly), `logs:prune-flow`, `logs:create-partition`
  (`routes/console.php`; the prune drops a partition under `lock_timeout` 5s through
  `Shared/Infrastructure/PostgresMonthlyPartitions`, and on a timeout the tenant is marked ✘ and the next run retries); `flow:node-usage` is run by hand and is not scheduled.
- Bindings: `Providers/FlowServiceProvider.php`. Registries are singletons, frozen after boot
  (not in the testing environment); engine, orchestrator, router and repositories are `scoped`.
  The node handler registry holds handler classes and builds each handler per `resolve()`
  through `NodeHandlerFactoryInterface` (ADR-0001). `VariableResolverInterface` is `scoped`,
  because it reads the current tenant's variable schema.
