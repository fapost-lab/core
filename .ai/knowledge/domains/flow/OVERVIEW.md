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
  - "app/Filament/Assistant/Resources/FlowSessions/**"
  - "app/Filament/Assistant/Resources/FlowLogs/**"
  - app/Console/Commands/CreateNextFlowLogPartitionCommand.php
  - app/Console/Commands/PruneFlowLogsCommand.php
reviewed_at: 2026-09-22
---
# Flow

## Responsibility

Flow executes published flow definitions for a contact. It routes an inbound message or event
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
| Triggers and events | `Services/Resolvers/`, `SyncFlowTriggerService`, `ResolveEventTriggersService`, `Events/`, `app/Jobs/Flow/` |
| Call, RAG, Action, Expression | `Call/`, `Rag/`, `Action/`, `Expression/` — small registries |
| History and logging | `History/`, `Logging/` (partitioned `flow_logs`) |
| Translations | `Translations/`, `app/Infrastructure/Flow/CachedContentTranslator.php` |

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
  (`ContentTranslatorInterface`). Contact↔Flow is an import cycle: `NotifyNodeHandler`
  dispatches Contact's `SendContactNotificationJob`.

## Entry points

- Inbound: Webhook's `IncomingMessageJob` (queue `flow.execution`) → `MessageRouter::route()` →
  `FlowOrchestrator::handle()` → `FlowEngine::start()` / `resume()`.
- Events and schedules: `StartFlowFromEventJob` (queue `scheduled.triggers`) and
  `DispatchFlowTriggerEventJob`. Timeouts: `ResumeTimedOutSendMessageNodeJob`.
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
- Console: `flow:sweep-subflow-timeouts` (every minute), `logs:prune-flow`, `logs:create-partition`
  (`routes/console.php`).
- Bindings: `Providers/FlowServiceProvider.php`. Registries are singletons, frozen after boot
  (not in the testing environment); engine, orchestrator, router and repositories are `scoped`.
  The node handler registry holds handler classes and builds each handler per `resolve()`
  through `NodeHandlerFactoryInterface` (ADR-0001). `VariableResolverInterface` is `scoped`,
  because it reads the current tenant's variable schema.
