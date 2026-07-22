# 11 · Implementation Plan (Phase A–E)

**Версия:** v2 (после brownfield audit + synthesis)
**Контекст:** план реализации с учётом существующего кода. См. [brownfield-audit.md](brownfield-audit.md) для inventory + conflict matrix, [synthesis.md](synthesis.md) для D-1..D-12 + Q-1..Q-4 решений.

> **Изначальный green-field план** заменён на phase-based brownfield migration. Sprint 4–6 sequence из v1 неприменим: foundation/registries/state/migrations большей частью уже существуют.

---

## Pre-flight: ADR blockers — все Accepted ✓

- ✓ B-1 [ADR Expression Language](../../architecture/adr/08-expression-language.md)
- ✓ B-2 [ADR Message Routing & Concurrency Control](../../architecture/adr/09-message-routing-concurrency.md)
- ✓ B-3 [ADR State Writer Semantics](../../architecture/adr/10-state-writer-semantics.md) — частично переопределён synthesis (D-3..D-6)
- ✓ B-4 [ADR Subflow Composition](../../architecture/adr/11-subflow-composition.md)
- ✓ Brownfield reconciliation closed (D-1..D-12, Q-1..Q-4 in [synthesis.md](synthesis.md))

## Фиксированные решения (от synthesis)

| # | Решение | Применяется в |
|---|---------|----------------|
| D-1 | NodeHandlerInterface — keep existing (non-static `type()`, `label`/`category`/`configSchema`, `execute(nodeConfig, state, context)`) | весь Phase B |
| D-2 | NodeExecutionContext — extend nullable services, no domain interfaces | Phase B-1 |
| D-3 | Result keeps `stateChanges`; only `effects[]` deprecated | Phase B-1 |
| D-4 | Foundation thin contracts; Core keeps rich `WriteContext`/`Policy`/`NamespaceResolverRegistry` | Phase A-1 ✓ + Phase B-1 |
| D-5 | ContactWriter replaces `effects[]` | Phase B-1 + B-2 |
| D-6 | HistoryLogger NOT in handler context; engine auto-instruments | Phase A-4 |
| D-7 | Type renames: `condition→branch`, `set_attribute→assign`, `webhook→call` (Q-1, Q-2) | Phase B-2 |
| D-8 | Foundation `MessageSenderInterface` — public; Core `FlowMessageSender` impl с `idempotencyKey` | Phase B (refactor) |
| D-9 | Migrations — pure additive (новые колонки/таблицы) | Phase A-2 ✓ |
| D-10 | Soft draft / strict publish — extend existing `ValidateFlowService`/`PublishFlowService` | Phase C-4 (subflow validation rules) |
| D-11 | `flow_logs` (operational) + `flow_session_history` (audit) coexist | Phase A-4 |
| D-12 | Octane: ExpressionEngine cache request-scoped | Phase A-3 ✓ |
| Q-1 | `set_attribute → assign`: rename | Phase B-2 |
| Q-1 | `webhook → call`: rename | Phase B-2 |
| Q-2 | `condition → branch`: rename | Phase B-2 |
| Q-3 | Context — string IDs only, no domain objects | Phase B-1 |
| Q-4 | `effects[]` deprecation: soft (silent ignore + logs) | Phase B-1 + Phase E |

---

## Phase A — Pure additive (parallel-safe)

Цель: ввести net-new infrastructure не трогая running code. Все задачи можно делать параллельно.

### A-1 ✓ Foundation contracts

**Done.** Files:

- `packages/fapost-foundation/src/Flow/Contracts/ScopedStateReaderInterface.php`
- `packages/fapost-foundation/src/Flow/Contracts/ContactWriterInterface.php`
- `packages/fapost-foundation/src/Flow/Contracts/ExpressionEngineInterface.php`
- `packages/fapost-foundation/src/Flow/Contracts/ExpressionEngineNotFoundException.php`
- `packages/fapost-foundation/src/Flow/Contracts/ExpressionEvaluationException.php`
- `packages/fapost-foundation/src/Flow/Contracts/ExpressionSyntaxException.php`
- `packages/fapost-foundation/src/Flow/DTO/ExpressionContext.php`

### A-2 ✓ Additive migrations

**Done.** 6 migrations в `database/migrations/tenant/`:

- `2026_05_06_000001_add_expression_engine_and_logging_to_flow_definitions.php`
- `2026_05_06_000002_add_subflow_columns_to_flow_sessions.php`
- `2026_05_06_000003_extend_flow_sessions_status_constraint.php`
- `2026_05_06_000004_create_flow_callgraph_edges_table.php`
- `2026_05_06_000005_create_flow_session_history_table.php`
- `2026_05_06_000006_add_commands_and_busy_message_to_assistants.php`

`tenant.settings.expression_engine` и `tenant.settings.timezone` живут в существующей `settings` table (key/value), отдельных колонок не нужно.

### A-3 ✓ ExpressionEngineRegistry + TemplateEngine

**Done.** Files:

- `app/Domains/Flow/Expression/ExpressionEngineRegistry.php`
- `app/Domains/Flow/Expression/Engines/TemplateEngine.php`
- `tests/Unit/Domains/Flow/Expression/TemplateEngineTest.php` (10 tests)
- `tests/Unit/Domains/Flow/Expression/ExpressionEngineRegistryTest.php` (4 tests)
- Wired в `FlowServiceProvider`: registered `TemplateEngine`, freeze в `booted()`

13/13 тестов проходят. 137/137 Flow tests passing.

### A-4 HistoryLogger (engine-driven instrumentation)

- **Files (new in foundation):**
  - `packages/fapost-foundation/src/Flow/History/HistoryEvent.php` (DTO)
  - `packages/fapost-foundation/src/Flow/History/HistoryEventType.php` (enum: state_change | subflow_started | subflow_returned | node_entered | node_failed)
- **Files (new in Core):**
  - `app/Domains/Flow/History/HistoryWriter.php` (writes to `flow_session_history` table)
  - `app/Domains/Flow/History/HistoryWriterInterface.php` (Core interface)
  - `app/Domains/Flow/History/NoOpHistoryWriter.php`
  - `app/Domains/Flow/History/HistoryWriterFactory.php` (resolve by `flow_definition.logging_enabled`)
  - `app/Domains/Flow/Models/FlowSessionHistoryEntry.php` (Eloquent)
- **Wiring:** factory bind в `FlowServiceProvider`. Engine intercepts state_changes from Result + node lifecycle events, dispatches via writer obtained from factory.
- **Note (D-6):** writer **не** инжектится в `NodeExecutionContext`. Auto-instrumentation полностью engine-controlled.
- **Tests:** unit per writer (with fake DB layer), integration с включённым `logging_enabled`.

### A-5 SessionLockManager + heartbeat

- **Files (new in Core):**
  - `app/Domains/Flow/Concurrency/SessionLockManager.php` (Redis SET NX with token, Lua script for token-checked release)
  - `app/Domains/Flow/Concurrency/LockHandle.php`
  - `app/Domains/Flow/Concurrency/LockScope.php`
  - `app/Domains/Flow/Concurrency/LockHeartbeat.php` (interval 10s, refresh +30s TTL, token-checked extend)
  - `app/Domains/Flow/Concurrency/LockAcquisitionPolicy.php` (3 retries × 2s)
  - `app/Domains/Flow/Exceptions/LockAcquisitionFailedException.php`
  - `app/Domains/Flow/Exceptions/LockOwnershipLostException.php`
- **Tests:** acquire/release/extend, token mismatch handling, retry counting.
- **Wiring:** singletons в `FlowServiceProvider`. Не подключается к routing pipeline до Phase D-1.

### A-6 ChannelInterface typing extension + Telegram impl

- **Files (modified в foundation):**
  - `packages/fapost-foundation/src/Channel/ChannelInterface.php` — добавить `indicateProcessing(string $chatId): ProcessingIndicatorHandle` и `stopProcessing(ProcessingIndicatorHandle $handle): void`
  - `packages/fapost-foundation/src/Channel/ProcessingIndicatorHandle.php` (DTO)
- **Files (modified в Core):**
  - `app/Domains/Channels/Telegram/TelegramAdapter.php` — implement methods через `sendChatAction`
- **Note:** существующие channel adapters (если есть, помимо Telegram) должны получить default no-op реализацию или быть обновлены.

### A-7 CallTransport layer

- **Files (new в foundation):**
  - `packages/fapost-foundation/src/Flow/Call/CallTransportInterface.php`
  - `packages/fapost-foundation/src/Flow/Call/CallRequest.php`
  - `packages/fapost-foundation/src/Flow/Call/CallContext.php`
  - `packages/fapost-foundation/src/Flow/Call/CallResult.php`
- **Files (new в Core):**
  - `app/Domains/Flow/Call/CallTransportRegistry.php` (fail-on-conflict)
  - `app/Domains/Flow/Call/Transports/HttpTransport.php` (id `'http'`, success_when policy)
  - `app/Domains/Flow/Call/Transports/HandlerTransport.php` (id `'handler'`, dispatches к ActionHandler)
  - `app/Domains/Flow/Call/ResultPathResolver.php` (для `result_mapping` с null tolerance)
- **Tests:** per transport, success_when matrix, missing path → null + warning.

### A-8 ActionHandlerRegistry + ActionHandlerInterface

- **Files (new в foundation):**
  - `packages/fapost-foundation/src/Action/ActionHandlerInterface.php`
- **Files (new в Core):**
  - `app/Domains/Flow/Action/ActionHandlerRegistry.php` (fail-on-conflict)
- **Wiring:** singleton, freeze. Подключается в HandlerTransport.

### A-9 BuiltinCommandsRegistry + Filament UI for tenant commands

- **Files (new в Core):**
  - `app/Domains/Flow/Commands/BuiltinCommandsRegistry.php` (`/reset`, `/cancel`)
  - `app/Domains/Flow/Commands/CommandMatcher.php`
  - `app/Domains/Flow/Commands/Actions/{TerminateSessionAction,StartFlowAction,SendMessageAction}.php`
  - `app/Domains/Flow/Commands/CommandExecutor.php`
  - `app/Domains/Flow/Validation/Rules/AssistantCommandsRule.php`
  - Filament: `app/Filament/Assistant/Resources/Assistants/AssistantCommandsRelationManager.php` (или встроенный repeater field)
- **Note:** existing `/reset` (если уже реализован) refactored под BuiltinCommandsRegistry в Phase D-3.

---

## Phase B — Existing refactor

Цель: переключить engine + handlers на новые контракты. Полный сценарий Phase A позволяет это сделать без блокирующих зависимостей.

### B-1 NodeExecutionContext + NodeExecutionResult refactor

- **Files (modified):**
  - `packages/fapost-foundation/src/DTO/NodeExecutionContext.php` — добавить три nullable поля (`stateReader`, `contactWriter`, `expressionEngine`). Existing fields kept.
  - `packages/fapost-foundation/src/DTO/NodeExecutionResult.php` — `effects[]` помечен `@deprecated`, остальное без изменений.
- **Files (new в Core):**
  - `app/Domains/Flow/State/ScopedStateReaderAdapter.php` — implements `ScopedStateReaderInterface`, делегирует в существующий `StateReader`
  - `app/Domains/Flow/State/Writers/ContactWriter.php` — implements `ContactWriterInterface`, пишет в Contact модель + emits history events через writer
- **Files (modified):**
  - `app/Domains/Flow/Services/FlowEngine.php` — заполняет три новых поля при построении `NodeExecutionContext`. `applyEffects()` остаётся для legacy handlers (deprecation warning при встрече).

### B-2 Refactor существующих handlers + rename types

> **Reconciliation D-7, Q-1, Q-2:** `condition`→`branch`, `set_attribute`→`assign`, `webhook`→`call`. Старые types остаются зарегистрированными для legacy snapshots; новые — параллельно. UI builder palette показывает только новые.

#### B-2.1 SendMessageNodeHandler

- Минимальный refactor: использовать `$context->expressionEngine` вместо `TemplateResolver` для substitution в text/caption.
- Migrate `effects[]` (нет — не использует) — N/A.
- Tests: обновить под новые контракты.

#### B-2.2 InputNodeHandler

- Refactor: использовать `$context->expressionEngine` для validation_error_message. Дополнить input_types: `phone`, `contact`, `location`, `file`, `photo`, `date` (с timezone resolution).
- Migrate `effects[]` для `set_contact_attribute` (когда input пишет в `contact.*`) → `$context->contactWriter->write()`.
- Tests + new tests per added input_type.

#### B-2.3 condition (kept) + branch (new)

- **Keep:** `ConditionNodeHandler` v1 (boolean) — для legacy snapshots. Не меняется.
- **New:** `BranchNodeHandler` (type `'branch'`, version 1) — multi-case с `default_handle` per `nodes/03-branch.md`. Поддерживает unary operators (`is_empty`/`is_null`/etc).
- Validator: rules `BranchUnaryOperatorRule`, `BranchOperandsRule`.
- Builder UI palette: показывает Branch, скрывает Condition.

#### B-2.4 set_attribute (kept) + assign (new)

- **Keep:** `SetAttributeNodeHandler` v1.
- **New:** `AssignNodeHandler` (type `'assign'`) — multi-op + literal target validation. Использует `$context->expressionEngine` для resolving values; пишет в session state через `result.stateChanges` или в contact через `$context->contactWriter`.
- Validator: rules `AssignTargetLiteralRule`, `VariablePathUniquenessRule`.

#### B-2.5 webhook (kept) + call (new)

- **Keep:** `WebhookNodeHandler` v1.
- **New:** `CallNodeHandler` (type `'call'`) — pluggable transport через `CallTransportRegistry`, `success_when` policy, `result_mapping` с null-tolerance.
- Validator: rules `CallTransportRule`, `CallTargetRule`, `CallResultMappingRule`.

#### B-2.6 DelayNodeHandler

- Минимальный refactor (если что-то нужно). Использует `$context->expressionEngine` для `absolute_at`.

### B-3 FlowEngine refactor + ContactWriter wiring

- **Files (modified):**
  - `app/Domains/Flow/Services/FlowEngine.php` — wire `ContactWriter` в transactional scope of execute. `applyEffects()` стает thin compatibility layer над ContactWriter (читает legacy `effects[]`, делегирует в writer).
  - `app/Domains/Flow/Services/FlowSessionPersister.php` — без серьёзных изменений (apply stateChanges).
- **History instrumentation:** engine listens to ContactWriter events через injected `HistoryWriterInterface`. Engine logs node_entered/node_failed/state_change events.
- **Optimistic-lock retry:** уже работает в existing code.

---

## Phase C — Net-new nodes

### C-1 EmitEventNodeHandler

- **File:** `app/Domains/Flow/Handlers/EmitEventNodeHandler.php` (type `'emit_event'`)
- Использует существующую `tenant_events` table + `TenantEventRepository`
- Trigger resolution через existing `ResolveEventTriggersService`

### C-2 EndNodeHandler

- **File:** `app/Domains/Flow/Handlers/EndNodeHandler.php` (type `'end'`)
- Mark session as ended (статус `ended` через расширенный enum, `end_status` column из A-2)
- Если `parent_session_id != null` — выполнить subflow resume (см. C-4)
- Emit `flow_completed` / `flow_cancelled` / `flow_failed` analytics

### C-3 RagQueryNodeHandler

- **File:** `app/Domains/Flow/Handlers/RagQueryNodeHandler.php` (type `'rag_query'`)
- Использует существующий foundation `RagAdapterInterface` + create `RagAdapterRegistry` (если ещё нет)
- Записывает `rag.*` через result.stateChanges
- Migration: `knowledge_bases` table (если нужна — может быть в Phase 4 RAG Feature, не V1)

### C-4 SubflowNodeHandler + lifecycle

- **Files (new):**
  - `app/Domains/Flow/Handlers/SubflowNodeHandler.php` (type `'subflow'`, ignore `input_mapping`/`output_mapping` в V1 — V1.1 forward-compat)
  - `app/Domains/Flow/Services/SubflowLifecycle.php` (start/end semantics, parent expiry extension)
  - `app/Domains/Flow/Validation/CallGraphValidator.php` (BFS forward + reverse, depth ≤ 3, recursion check, cross-assistant)
  - `app/Domains/Flow/Repositories/CallGraphEdgesRepository.php` (FOR UPDATE при publish)
  - `app/Jobs/Flow/SubflowTimeoutJob.php` + schedule
  - `app/Domains/Flow/Validation/Rules/SubflowCycleRule.php`, `SubflowSameAssistantRule.php`, `SubflowDepthRule.php`, `SubflowTargetExistsRule.php`
- **Files (modified):**
  - `app/Domains/Flow/Services/PublishFlowService.php` — wire CallGraphValidator, atomic edges update
  - `app/Domains/Flow/Services/ValidateFlowService.php` — добавить subflow rules; differentiate draft (warnings) vs publish (errors) per D-10
- **EndHandler integration (C-2):** при `parent_session_id` — resume parent через `parent_resume_node_id` + handle согласно `end_status`
- **Routing rule:** в Phase D-1 — `paused_subflow` parent → найти child в active/waiting_input → message routes to child

---

## Phase D — Routing pipeline integration

### D-1 MessageRouter + DropPolicy + SessionStateRouter

- **Files (new):**
  - `app/Jobs/Messaging/ProcessIncomingMessageJob.php` (или refactor existing) — оркестрирует 6 шагов pipeline (см. ADR Message Routing § "Message Routing Pipeline")
  - `app/Domains/Flow/Routing/MessageRouter.php` — координатор: command match → typing → lock → state check → execute → cleanup
  - `app/Domains/Flow/Routing/SessionStateRouter.php` — paused_subflow / active / waiting_input / terminal → trigger
  - `app/Domains/Flow/Routing/DropPolicy.php` — busy notice (assistants.busy_message) vs silent drop
- **Tests:** integration — concurrent inputs, lock acquisition timeout, drop policy.

### D-2 TypingIndicatorService integration

- **Files (new):**
  - `app/Domains/Messaging/Typing/TypingIndicatorService.php`
  - `app/Domains/Messaging/Typing/TypingSession.php`
- **Wiring:** `MessageRouter` Step 2 — `TypingIndicatorService->start()`. `LockHeartbeat` co-schedule typing refresh каждые 4s. Engine `refresh()` перед call/rag_query.

### D-3 Global commands integration + /reset migration

- **Wiring:** `MessageRouter` Step 1 заменяет stub command match на `CommandMatcher` (из A-9).
- **Migrate existing `/reset`** под `BuiltinCommandsRegistry` — backward-compat preserved.
- **Tests:** /reset во время active execution с force unlock.

---

## Phase E — Cleanup + finalisation

- Удалить `effects[]` поле из `NodeExecutionResult` (после миграции всех handlers в B-2)
- Удалить `applyEffects()` из FlowEngine
- Удалить deprecated `TemplateResolver` если все handlers перешли на ExpressionEngine
- phpat правила:
  - `NodeHandlerInterface` impls только в `app/Domains/Flow/Handlers/` или packages
  - `CallTransportInterface` impls только в `app/Domains/Flow/Call/Transports/` или packages
  - `ActionHandlerInterface` impls только в `app/Domains/*/Actions/` или packages
  - Foundation не зависит от Core (`use App\*` запрещён в `packages/fapost-foundation/`)
  - Inline `'system.*'`/`'flow.*'` строки запрещены в handlers — только через `SystemStateKeys` константы
  - Migrations не используют `app()`/`config()`/`TenantContext::get()`
  - `HistoryWriterInterface` impls только `Default` и `NoOp`; plugins не подменяют
- Final integration tests (см. acceptance criteria ниже)
- Documentation update в Notion

---

## Dependencies graph

```
Phase A (parallel):
  ✓ A-1 → ✓ A-2 → ✓ A-3
  A-4, A-5, A-6, A-7, A-8, A-9 (independent)

Phase B (after A done):
  B-1 → B-2 (handler-by-handler) → B-3

Phase C (after B-1, foundation/migrations):
  C-1, C-2, C-3 (independent)
  C-4 (depends on full validator framework + EndHandler from C-2)

Phase D (after A-5, A-9, B-3):
  D-1 → D-2, D-3 (parallel)

Phase E (after all of B, C, D):
  cleanup
```

---

## Acceptance criteria for V1

V1 считается готовым когда:

- [ ] Все 10 V1 node types реализованы и зарегистрированы (`send_message`, `input`, `branch`, `assign`, `call`, `delay`, `emit_event`, `rag_query`, `subflow`, `end`)
- [ ] Legacy handlers (`condition`, `set_attribute`, `webhook`) сохранены для legacy snapshots
- [ ] CallTransportRegistry содержит `http` и `handler` транспорты
- [ ] `effects[]` удалён из NodeExecutionResult, все handlers используют ContactWriter
- [ ] Validation framework: 6 уровней + draft/publish дифференциация + subflow rules
- [ ] Group storage discovery cache работает (existing — needs verification)
- [ ] Subflow lifecycle тесты: success / cancelled / failed / timeout / lock chain
- [ ] Concurrency hardening tests: distributed lock, heartbeat, optimistic lock retry
- [ ] /reset migrated под BuiltinCommandsRegistry, integration tests на forced unlock
- [ ] Routing pipeline 6 шагов end-to-end с typing indicator
- [ ] History logging opt-in работает; parent/child independent histories с navigation events
- [ ] phpat правила enforced (см. Phase E)
- [ ] Filament read-only UI для flow_definitions / flow_sessions / analytics_events

---

## Risks

- **R-1 ✓ closed.** Expression engine choice — closed by ADR Expression Language (TemplateEngine V1).
- **R-2.** ChannelInterface extension (A-6) coordination с активной разработкой Telegram adapter.
- **R-3.** RAG adapter contract finalization. Если затягивается → C-3 переносится в V1.x.
- **R-4.** SubflowHandler + CallGraphValidator (C-4) — самая сложная одиночная задача. Mitigation: изолировать в feature branch, параллелить с другими C-задачами.
- **R-5.** Octane lifecycle: ExpressionEngine cache (A-3 done) — request-scoped, не cross-request. Verify в end-to-end test.
- **R-6.** Brownfield refactor (Phase B) — handler-by-handler с обновлением тестов. Если параллельная разработка касается одного handler — координация необходима.

---

## What's done so far

| Task | Status | Notes |
|------|--------|-------|
| B-1..B-4 ADRs | ✓ Accepted | All four blockers closed |
| Brownfield audit + synthesis | ✓ | [brownfield-audit.md](brownfield-audit.md) + [synthesis.md](synthesis.md) |
| A-1 Foundation contracts | ✓ | 7 new interfaces/DTO/exceptions |
| A-2 Migrations | ✓ | 6 additive migrations |
| A-3 ExpressionEngine + TemplateEngine | ✓ | Wired в FlowServiceProvider, 13/13 tests passing |

**Все 137 Flow tests passing после A-1..A-3.**

---

## Связано с

- [[README]] — flow engine README
- [[synthesis]] — синтез
- [[TASKS]] — трекер задач
- [[nodes/README]] — каталог нод
- [[ROADMAP]] — дорожная карта платформы
- [[06-flow-engine]] — flow engine архитектура
