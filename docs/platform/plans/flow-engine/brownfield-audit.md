# 12 · Brownfield Audit & Reconciliation

**Дата:** Апрель 2026
**Статус:** результаты разведки кодовой базы перед началом реализации
**Цель:** зафиксировать делту между уже существующим кодом и тем, что требуют принятые ADR. Дать честный план миграции вместо green-field имплементации.

> **Важно:** [implementation-plan.md](implementation-plan.md) был написан в предположении green-field. Реальность — продвинутый brownfield. Этот документ — поправка к планированию, не replacement самого плана. Где-то заходит в конфликт с планом — этот аудит имеет приоритет.

---

## 1. Что уже работает

### 1.1 Foundation package (`packages/fapost-foundation/`)

Уже существуют:

| Контракт | Файл | Расходится с ADR? |
|----------|------|--------------------|
| `NodeHandlerInterface` | `Contracts/NodeHandlerInterface.php` | **Да:** есть `label()`/`category()`/`configSchema()`, отсутствует `static type()`. Принимает `(array $nodeConfig, array $state, NodeExecutionContext $context)`. |
| `NodeExecutionContext` | `DTO/NodeExecutionContext.php` | **Да:** string IDs, `idempotencyKey`/`platform`/`resolvedLanguage`/`incoming`. ADR требует объекты + reader/writer/expression-engine/history-logger. |
| `NodeExecutionResult` | `DTO/NodeExecutionResult.php` | **Да:** есть `status` enum + `stateChanges`/`logResolved`/`effects`/`metadata` (handler-returns-changes pattern). ADR требует только `sourceHandle` + `errorMeta`. |
| `MessageSenderInterface` | `Messaging/MessageSenderInterface.php` (foundation) **и** `Flow/Contracts/MessageSenderInterface.php` (Core) | Двойственность: один в foundation, один в Core. Подпись отличается. |
| `OutgoingMessage`, `SendResult` | `DTO/`, `Messaging/DeliveryResult.php`, `Messaging/OutboundMessage.php` | Несколько DTO для одного и того же. Нужна consolidation. |
| `RagAdapterInterface`, `StructuredRagResult`, `RagQueryContext` | `Contracts/`, `DTO/` | Похоже соответствует |
| `DataAccessorInterface` | `Contracts/DataAccessorInterface.php` | Есть; подходит под спеку |
| `ActivatableInterface`, `SolutionManifest` | `Contracts/`, `Manifest/` | Есть |
| `CoreRegistrarInterface` | `Contracts/CoreRegistrarInterface.php` | Есть |
| `AnalyticsWriterInterface`, `AnalyticsEvent`, `AnalyticsEventType` | `Analytics/...` | Есть |
| `ChannelInterface` (не `ChannelAdapterInterface`) | `Channel/ChannelInterface.php` | **Нет** методов `indicateProcessing` / `stopProcessing` (ADR Message Routing) |
| `AbstractVersionedHandler` | `Flow/Handlers/AbstractVersionedHandler.php` | Базовый класс для handlers; реализует часть NodeHandlerInterface |
| `TriggerResolverInterface`, `TriggerTypeResolverInterface`, `ResolvedTrigger`, `TriggerContext` | `Flow/Contracts/`, `Flow/DTO/` | Есть |
| `KeyboardMode` enum | `Flow/Enums/KeyboardMode.php` | Есть |
| `WebhookRegistrarInterface`, `WebhookRegistrationPayload` | `Channel/` | Есть |
| `MediaBlobReadInterface`, downloader/uploader interfaces, `UploadResult`, `DownloadResult`, `MediaKind` | `Media/...` | Есть |

**Что НЕТ в foundation:**
- `ScopedStateReaderInterface` / `ScopedStateWriterInterface`
- `HistoryLoggerInterface`
- `ExpressionEngineInterface` / `ExpressionContext`
- `CallTransportInterface` / `CallRequest` / `CallContext` / `CallResult`
- `ActionHandlerInterface`
- `ContactInterface`, `FlowSessionInterface`, `FlowDefinitionInterface`, `TenantInterface` (живут как Eloquent-модели в Core)

### 1.2 Flow domain (`app/Domains/Flow/`)

**Контракты (в Core, не в foundation):**

`FlowEngineInterface`, `FlowSessionRepositoryInterface`, `FlowDefinitionRepositoryInterface`, `FlowDraftRepositoryInterface`, `FlowOrchestratorInterface`, `FlowExecutionGuardInterface`, `MessageSenderInterface`, `NodeHandlerRegistryInterface`, `DataAccessorRegistryInterface`, `MutableDataAccessorRegistryInterface`, `LanguageResolverInterface`, `ContentTranslatorInterface`, `FallbackMessageServiceInterface`, `FlowSessionInterface`, `HttpClientInterface`, `InlineKeyboardEditorInterface`, `PersistentButtonRegistryInterface`, `FlowTriggerRepositoryInterface`, `FlowTriggerConfigValidatorInterface`, `TenantEventRepositoryInterface`, `TenantTranslationServiceInterface`, `TenantTranslationRepositoryInterface`.

**Models:** `FlowDefinition`, `FlowSession`, `FlowDraft`, `FlowGroup`, `FlowTrigger`, `TenantEvent`, `TenantTranslation`, `PersistentInlineButton`.

**Services (handlers/engine/persistence):**

- `FlowEngine` (444 строки) — production-ready execution loop с optimistic lock, analytics, partitioned logging, concurrency exceptions, language resolution.
- `FlowGraphResolver` — resolveEntryNode / resolveNextNode / findNode.
- `FlowSessionPersister` — incapsulates save logic.
- `FlowMessageSender` — реализация sender с FlowMessageSenderTest покрытием.
- `LanguageResolver`, `ContentTranslator`/`CachedContentTranslator`, `FallbackMessageService`.
- `PublishFlowService`, `SaveDraftService`, `LoadBuilderFlowService`, `ValidateFlowService`, `SyncFlowTriggerService`, `ResolveEventTriggersService`.
- `FlowInlineKeyboardEditor`, `PersistentButtonRegistry`.
- Trigger resolvers (`Resolvers/`): `TriggerResolver`, `MessageTriggerResolver`, `WebhookTriggerResolver`, `ScheduleTriggerResolver`, `ApiTriggerResolver`.

**State infrastructure (`Domains/Flow/State/`):**

`FlowState`, `FlowStateNamespace`, `StatePath`, `StateNamespace` (enum), `StateReader`, `StateWriter`, `WriteContext`, `NamespaceWritePolicy`, `Resolvers/NamespaceResolverRegistry`, `SystemStateKeys`, `RagStateKeys`. Архитектура **NamespaceResolver + WriteContext + Policy** — фактически богаче того, что описано в ADR State Writer Semantics (в ADR этих primitive нет, но есть концепция immediate writes которая накладывается поверх).

**Handlers (`Domains/Flow/Handlers/`):**

| Existing | Соответствие V1 spec |
|----------|----------------------|
| `SendMessageNodeHandler` | ≈ `send_message` (V1 нода) |
| `InputNodeHandler` | ≈ `input` |
| `ConditionNodeHandler` | частично `branch` (Condition = boolean, без switch-case) |
| `DelayNodeHandler` | ≈ `delay` |
| `SetAttributeNodeHandler` | частично `assign` (single-op) |
| `WebhookNodeHandler` | частично `call` (только HTTP, без `handler` транспорта) |

**Чего нет среди handlers:** Branch (multi-case), Assign (multi-op), Call (с pluggable transport), EmitEvent, Subflow, RagQuery, End.

**Exceptions:** `FlowConcurrencyException`, `OptimisticLockConflictException`, `FlowExecutionLimitExceededException`, `HandlerNotFoundException`, `InvalidFlowGraphException`, `InvalidNodeConfigException`.

**Logging:** `Logging/FlowLogWriter`, `Logging/FlowLogEntry`, `Logging/FlowLogStatus`, `Logging/DatabaseAnalyticsWriter` — пишут в `flow_logs` (партиционированную) и `analytics_events`.

### 1.3 Migrations (тенантная схема, 36 шт.)

| Уже есть | Detail |
|----------|--------|
| `flow_definitions` | `id`, `tenant_id`, `flow_id`, `version`, `name`, `nodes` JSONB, `edges` JSONB, `is_active`. Уникальный индекс `(tenant_id, flow_id) WHERE is_active = true`. |
| `flow_sessions` | `id`, `tenant_id`, `assistant_id`, `contact_id`, `flow_definition_id`, `flow_version`, `current_node_id`, `state` JSONB, `status` (enum check: `pending`/`active`/`waiting_input`/`paused`/`completed`/`failed`/`cancelled`), `version`, `expires_at`, timestamps. |
| `flow_logs` | Партицированная по `created_at` monthly с auto-rolling 3 месяцев вперёд. Колонки `state_changes`, `resolved`, `error`. |
| `analytics_events` | `tenant_id`, `event_type`, `payload`, `occurred_at`, индекс `(tenant_id, event_type, occurred_at)`. |
| `assistants` | `id`, `tenant_id`, `name`, `is_active`, `default_flow_id`, `fallback_message`, `settings` JSONB. Затем добавлен `default_language`. |
| `contacts` | `id`, `tenant_id`, `platform`, `external_id`, `meta` JSONB, `attributes` JSONB. Затем добавлен canonical `language`. |
| `flow_drafts`, `flow_groups`, `flow_triggers`, `tenant_events`, `tenant_translations`, `persistent_inline_buttons`, `channel_contacts`, `channels` | Все есть |

**Чего НЕТ в DB:**

| Что нужно | Для чего |
|-----------|----------|
| `flow_sessions.parent_session_id` (uuid, FK self) + index | Subflow lifecycle (ADR Subflow Composition) |
| `flow_sessions.parent_resume_node_id` (string) | Subflow resume coordinate |
| `flow_sessions.end_status` (enum: success/cancelled/failed) | End node status (ADR / Subflow) |
| Расширение `flow_sessions.status` enum: добавить `paused_subflow`, `terminated_by_user`, `expired`, `ended` | Subflow + commands + history |
| `flow_definitions.expression_engine` (text NOT NULL) | ADR Expression Language |
| `flow_definitions.logging_enabled` (boolean default false) | ADR State Writer Semantics |
| `flow_callgraph_edges` (caller_flow_id, callee_flow_id, caller_definition_id) | ADR Subflow cycle prevention |
| `flow_session_history` (session_id, node_id, event_type, path, old/new value, metadata, created_at) | ADR State Writer history |
| `assistants.commands` (JSONB default '[]') | ADR Message Routing global commands |
| `assistants.busy_message` (text nullable) | ADR Message Routing drop notice |
| `tenant_settings.timezone` или `tenants.settings.timezone` (default `'UTC'`) | Date input parsing |
| `tenants.settings.expression_engine` (default `'template'`) | ADR Expression Language |
| `flow_logs.attempt_number` (если будем отслеживать) | V1.x — для V1 не нужен |

### 1.4 Tests — coverage существенный

29 тестов в Flow domain:

- `FlowEngineTest`, `FlowEngineHandlerNotFoundTest`, `FlowOrchestratorTest`
- `FlowSessionOptimisticLockTest`, `FlowSessionSchemaTest`
- `FlowMessageSenderTest`, `FlowGraphResolverTest`, `FlowDefinitionValidatorTest`
- `BuiltInNodeHandlersTest`, `NodeHandlerRegistryTest`, `BuilderApiTest`
- `PublishFlowServiceTest`, `SaveDraftServiceTest`, `CreateFlowActionTest`
- `ResolveEventTriggersServiceTest`, `MessageTriggerResolverTest`
- `LanguageResolverTest`, `ContentTranslatorTest`, `TenantTranslationServiceTest`
- `StateContractTest`, `ModuleDataAccessorRegistryTest`, `FlowExecutionGuardTest`
- `Logging/DatabaseAnalyticsWriterTest`, `Logging/FlowLogWriterTest`
- `ValidateFlowServiceTest`, `ValidateFlowServiceMediaTest`
- `Filament/Assistant/Resources/Flows/FlowsTableTest`, `FlowDefinitionMediaReferenceExtractorTest`
- `Console/PruneFlowLogsCommandTest`

Все они тестируют **существующую stateChanges-based архитектуру**. Любой refactor контрактов сломает большинство.

### 1.5 Channels

- `app/Domains/Channels/Telegram/TelegramAdapter.php` + 10 связанных классов (BotApiClient, BotIdentityStore, WebhookRegistrar, SignatureVerifier, InboundNormalizer, photo/video/voice/document delivery actions)
- `ChannelInterface` в foundation — без typing методов

---

## 2. Конфликт matrix (существующее vs ADR)

### 2.1 Архитектурные модели — несовместимы

| Aspect | Существующее | ADR требует | Тип конфликта |
|--------|--------------|-------------|---------------|
| Write модель | Handler возвращает `stateChanges`/`effects`, engine применяет через `FlowSessionPersister` + `applyEffects` | Handler пишет immediately через `ScopedStateWriter` | **Architectural** — ломает все handlers и engine loop |
| Side-effects | `effects[]` в Result (set_contact_attribute / set_contact_language) | Direct write через writer + ContactWriter sub-router | Architectural |
| State namespace dispatch | `NamespaceResolver + Policy + WriteContext` (более богато) | Простой routing по префиксу | **Existing богаче** — ADR можно подстроить |
| NodeHandlerInterface | non-static `type()`, есть `label()`/`category()`/`configSchema()` | static `type()`, без UI методов | Conventional |
| NodeExecutionContext | `tenantId`/`contactId`/`sessionId` строки + `incoming` IncomingMessage | объекты `TenantInterface` etc + reader/writer/expr/history | Refactoring |
| NodeExecutionResult | `status` enum + `stateChanges`+`effects`+`logResolved`+`metadata`+`errorMessage` | `sourceHandle` + `errorMeta` | Major simplification |
| MessageSender contract | в Core `Domains/Flow/Contracts/`, отдельная подпись | в foundation, с idempotencyKey | Migration |
| `IncomingMessage` в context | inline в context | Routing концепция, не поле context | Conceptual |

### 2.2 Что **совпадает** (можно переиспользовать)

- Migrations infrastructure: tenant schema, partitioned `flow_logs`, `analytics_events`, `flow_definitions`, `flow_sessions` — все есть
- `FlowGraphResolver`, `FlowSessionPersister`, `FlowLogWriter`, `DatabaseAnalyticsWriter`
- Optimistic lock на `flow_sessions.version` — реализован, тестирован
- `FlowConcurrencyException`, `OptimisticLockConflictException` — соответствуют ADR
- `LanguageResolver`, `ContentTranslator` — отдельный multilingual слой, не входит в ADR
- `FlowState`, `StatePath`, `StateReader`, `StateWriter` (API совместимое в общих чертах с ADR)
- `FlowMessageSender` (рабочий sender, нужен только enrichment idempotencyKey-параметром)
- Trigger resolvers — отдельный домен от subflow, остаётся
- 6 существующих handlers как **rough drafts** новых V1 нод (миграция, не переписывание с нуля)

### 2.3 Что **дополнительное** (нет в ADR, есть в коде)

- `FlowDraft` model + draft persistence — отдельный механизм builder UI, не описан в ADR
- `FlowGroup` — UI-organization concept
- `FlowTrigger` + `tenant_events` + 5 trigger resolvers — Phase 2 task 14, отдельная фича
- `TenantTranslation` + `ContentTranslator` — multilingual content (отдельная V1 фича из CLAUDE.md, не входит в эту спеку)
- `PersistentInlineButton` — UX механизм inline keyboard re-entry
- `FlowExecutionGuard` — limits / quotas
- `effects[]` в Result для cross-domain side-effects
- WriteContext с origin tracking (engine/node/builder/accessor)

ADR не описывает эти куски — но они есть и должны сохраниться при любом refactor.

---

## 3. Categorization: что reuse / refactor / new

### 3.1 Reuse as-is (не трогаем)

- Migrations: `flow_definitions`, `flow_sessions`, `flow_logs`, `analytics_events`, `flow_drafts`, `flow_groups`, `flow_triggers`, `tenant_events`, `tenant_translations`, `persistent_inline_buttons`, `assistants`, `contacts`
- `FlowGraphResolver`, `FlowSessionPersister`, `FlowLogWriter`, `DatabaseAnalyticsWriter`
- `LanguageResolver`, `ContentTranslator`, multilingual слой целиком
- `FlowExecutionGuard`, `FlowDraft`, `FlowGroup`, `FlowTrigger`, trigger resolvers
- `OptimisticLockConflictException` и saveWithOptimisticLock
- `WriteContext`, `NamespaceWritePolicy`, `NamespaceResolverRegistry`, `StatePath`, `FlowState` (state primitives — лучше чем то, что я предлагал в ADR)
- `PersistentInlineButton`, `FlowInlineKeyboardEditor`
- Filament resources, Builder UI, BuilderApi, `SaveDraftService`, `PublishFlowService`, `ValidateFlowService`, `LoadBuilderFlowService`

### 3.2 Refactor (существующее меняется под ADR)

| Item | Изменения |
|------|-----------|
| `NodeHandlerInterface` (foundation) | Перевод на `static type()` (опционально — оставить non-static и не ломать handlers); решить судьбу `label()`/`category()`/`configSchema()` (вероятно оставить — это не противоречит ADR, просто ADR их не упомянул) |
| `NodeExecutionContext` (foundation) | Добавить `ScopedStateReader`/`ScopedStateWriter`/`ExpressionEngine`/`HistoryLogger` + объекты `Contact`/`Session`/`Definition`. **Сохранить совместимость** через @deprecated на старых string-ID полях во время transition |
| `NodeExecutionResult` (foundation) | Постепенная упрощение: добавить новые статические конструкторы, deprecate `stateChanges`/`effects`/`logResolved` (engine начнёт читать через writer вместо результата) |
| Все 6 handlers | Переписать execute() на использование writer вместо `stateChanges`/`effects`. Это **большая работа** — каждый handler с тестами |
| `FlowEngine` | applyEffects/persist через новый writer. `applySystemStateEffects` уйдёт. Логика optimistic-lock retry остаётся |
| `MessageSenderInterface` (Core) | Унификация с foundation; добавить `idempotencyKey` параметр; `SentMessageResult` с `wasDeduplicated` (V1 всегда `false`) |
| Migrations (additive) | Новые колонки/таблицы (см. §1.3 — что НЕТ в DB) — каждая отдельная миграция |
| `flow_sessions.status` constraint | Расширить enum `paused_subflow`, `terminated_by_user`, `ended` (deprecate `completed` или alias) |
| `FlowSessionPersister` | Адаптировать под immediate-write модель |
| `ChannelInterface` (foundation) | Добавить `indicateProcessing`/`stopProcessing` методы, `ProcessingIndicatorHandle` DTO |
| `TelegramAdapter` | Реализация typing через `sendChatAction` |

### 3.3 New (не существует, чисто green-field)

- `ExpressionEngineInterface` + `ExpressionContext` + `ExpressionEngineRegistry` + built-in `TemplateEngine`
- `HistoryLoggerInterface` + `DefaultHistoryLogger` + `NoOpHistoryLogger` + `HistoryLoggerFactory`
- `flow_session_history` table + `flow_callgraph_edges` table
- `SessionLockManager` + `LockAcquisitionPolicy` + `LockHeartbeat` + `LockHandle`/`LockScope` + соответствующие exceptions
- `BuiltinCommandsRegistry` + `CommandMatcher` + Action implementations (`TerminateSessionAction`, `StartFlowAction`, `SendMessageAction`) + `CommandExecutor`
- `MessageRouter` + `SessionStateRouter` + `DropPolicy` + `ProcessIncomingMessageJob` (если ещё нет; если есть — refactor)
- `TypingIndicatorService` + `TypingSession` + `ProcessingIndicatorHandle`
- `CallTransportInterface` + `CallTransportRegistry` + `CallRequest`/`CallContext`/`CallResult` + `HttpTransport` + `HandlerTransport`
- `ActionHandlerInterface` + `ActionHandlerRegistry`
- Subflow inrastructure: `SubflowHandler` + `SubflowLifecycle` + `CallGraphValidator` + `CallGraphEdgesRepository` + `PublishFlowDefinitionService` (новый, отдельный от существующего `PublishFlowService` или замена) + `SubflowTimeoutJob`
- New nodes: `Branch` (multi-case, заменяет/расширяет `Condition`), `Assign` (multi-op, заменяет `SetAttribute`), `EmitEvent`, `RagQuery`, `End`

---

## 4. Revised plan strategy

### 4.1 Принципы новой стратегии

1. **Не ломать прод.** Существующие handlers и FlowEngine продолжают работать через transition period.
2. **Additive до refactor.** Сначала добавляем чистого нового (ExpressionEngine, HistoryLogger, SessionLockManager, новые таблицы). Они не пересекаются с running code.
3. **Migrate handler-by-handler.** Каждый existing handler рефакторится в отдельной задаче, с переписыванием своих тестов.
4. **Сохранить state primitives.** `WriteContext`/`NamespaceWritePolicy`/`NamespaceResolverRegistry` остаются — мы только добавляем `ScopedStateReader`/`ScopedStateWriter` как foundation-facing facade поверх них.
5. **Backward-compat в context/result.** Расширяем context новыми полями, deprecate старые. Result: добавляем simplified конструктор, оставляем legacy API на время.
6. **Тесты обновляем по мере refactor.** Не прыгаем "все сразу".

### 4.2 Новая последовательность задач (поверх implementation-plan.md)

#### Phase A — Pure additive (можно делать параллельно, ничего не ломает)

- **A-1 Foundation: новые контракты.** `ScopedStateReaderInterface`, `ScopedStateWriterInterface`, `HistoryLoggerInterface`, `ExpressionEngineInterface`, `ExpressionContext`. Просто файлы в foundation, никакой интеграции.
- **A-2 Migrations (additive).** Добавить колонки/таблицы которых нет:
  - `flow_definitions`: `expression_engine` (default `'template'`), `logging_enabled` (default `false`)
  - `flow_sessions`: `parent_session_id`, `parent_resume_node_id`, `end_status`
  - `flow_sessions.status`: расширить constraint enum
  - `assistants`: `commands` JSONB, `busy_message`
  - `tenants`/tenant settings: `timezone`, `expression_engine`
  - Новые таблицы: `flow_session_history`, `flow_callgraph_edges`
- **A-3 ExpressionEngineRegistry + TemplateEngine.** Реализация по ADR. Не подключается к running engine, тесты автономны.
- **A-4 HistoryLogger (Default + NoOp).** Реализация. NoOp — default везде, DefaultHistoryLogger готов к включению.
- **A-5 SessionLockManager + LockAcquisitionPolicy + LockHeartbeat.** Чистая infrastructure поверх Redis.
- **A-6 ChannelInterface extension: typing methods.** Добавить методы (default no-op в interface через DefaultMethod либо через abstract); расширить `TelegramAdapter` реализацией. Не подключается в pipeline пока.
- **A-7 CallTransportRegistry + HttpTransport + HandlerTransport + foundation contracts.** Новая infrastructure, не интегрируется пока.
- **A-8 ActionHandlerRegistry.** Чистая infrastructure.
- **A-9 BuiltinCommandsRegistry + tenant commands schema validation + Filament resource.** Поверх новой колонки `assistants.commands`.

#### Phase B — Existing refactor (handler-by-handler)

- **B-1 NodeExecutionContext additive extension.** Добавить новые опциональные поля (`stateReader`, `stateWriter`, `historyLogger`, `expressionEngine`). Старые поля остаются. Engine начинает заполнять оба варианта.
- **B-2 NodeExecutionResult: новые конструкторы.** Добавить минимальный API (`success(handle)`, `error(handle)`). Старый API остаётся.
- **B-3 SendMessageNodeHandler refactor.** Переписать execute на writer. Тесты обновить. Соответствует существующей V1-spec ноде `send_message`. Результат: handler работает по новой модели, engine integrate новый result через legacy support.
- **B-4 InputNodeHandler refactor.** Аналогично. Добавить новые `input_type` (которых нет: phone, contact, location, file, photo, date с timezone) — отдельной подзадачей.
- **B-5 ConditionNodeHandler → BranchNodeHandler.** Расширить до multi-case (заменяет single-condition). Backward-compat: при двух кейсах (`true`/`false`) ведёт себя как Condition. Тесты дополнить.
- **B-6 SetAttributeNodeHandler → AssignNodeHandler.** Расширить до multi-op + literal target. Тесты дополнить.
- **B-7 DelayNodeHandler refactor.** Минимальные изменения.
- **B-8 WebhookNodeHandler → CallNodeHandler.** Pluggable transport, success_when policy, result_mapping. Webhook становится частным случаем `http` транспорта.

#### Phase C — Net-new nodes

- **C-1 EmitEventNodeHandler.** Использует существующий `tenant_events` + trigger resolution.
- **C-2 RagQueryNodeHandler.** Использует foundation `RagAdapterInterface`. Создать registry если нет.
- **C-3 EndNodeHandler.** Включая subflow resume logic.
- **C-4 SubflowNodeHandler + lifecycle services + cycle validator + timeout job.** Самая сложная задача (см. ADR Subflow Composition).

#### Phase D — Routing pipeline integration

- **D-1 MessageRouter + DropPolicy + SessionStateRouter.** Wire SessionLockManager в incoming-message обработку. До этого incoming идёт через текущий код.
- **D-2 TypingIndicatorService integration.** Wire в pipeline.
- **D-3 Global commands integration.** CommandMatcher в pipeline, refactor existing `/reset` под новую architecture.

#### Phase E — Cleanup

- Удалить deprecated поля из `NodeExecutionContext`/`NodeExecutionResult` после того как все handlers мигрированы.
- Унифицировать `MessageSenderInterface` (foundation vs Core).
- phpat правила (CC-1) на новые контракты.

### 4.3 Sequencing

Phases можно частично параллелить:
- Phase A полностью параллельна (разные файлы, разные таблицы)
- Phase B sequence per handler, но handlers независимы — можно несколько разработчиков
- Phase C зависит от B-3 (Send) и foundation contracts из A
- Phase D зависит от A-5 (lock) + A-9 (commands) + B завершён частично
- Phase E финальная

Если подходить к этому одной командой и не торопиться — **6–8 недель** реалистично (исходный план 11 заявлял Sprint 4–6 при предположении green-field, что нереалистично).

### 4.4 Что переезжает из implementation-plan.md без изменений

- B-1..B-4 (ADR) — все приняты, не зависят от brownfield
- S6-7 read-only Filament UI — additive
- Большинство acceptance criteria — корректны, но привязка к Sprint 4–6 пересмотрена
- Cross-cutting concerns CC-2..CC-4 — корректны
- Phpat CC-1 — корректно по сути, но конкретные правила должны учитывать legacy API на transition period

### 4.5 Что в implementation-plan.md устарело

- Sprint 4–6 sequence (foundation → migrations → registries → state → ...) — пишет с нуля, тогда как многое существует
- S4-1 (Foundation contracts) — нужно дописать только то, чего нет
- S4-2 (Migrations) — большинство миграций уже есть, нужны только додатки
- S4-4 (State infrastructure) — `StateReader`/`StateWriter`/`WriteContext` уже есть и богаче ADR; нужно adapter поверх для foundation-facing facade
- S4-6 (FlowEngine execution loop) — engine уже есть, нужен incremental refactor, не green-field build
- S4-7 (MessageSender) — `FlowMessageSender` есть, нужно добавить `idempotencyKey`
- S4-8 (SendMessage + End handlers) — SendMessage есть, нужен refactor; End — net-new
- S4-9 (integration test minimal flow) — `FlowEngineTest` уже покрывает основные сценарии

Все эти задачи переформулируются в Phase A–E выше.

---

## 5. Risk & decisions

### 5.1 Главные риски

- **R-A. Параллельная разработка.** Если Phase A вы стартуете до окончания B (refactor), test fixtures старых handlers начнут расходиться с новыми контрактами. Mitigation: Phase A полностью additive, не ломает existing.
- **R-B. WriteContext / NamespaceResolver migration.** Существующие state primitives надо сохранить и не ломать; new `ScopedStateReader/Writer` — это **wrapper** поверх них, не замена. Стоит оформить отдельным мини-ADR (который ADR State Writer Semantics не учитывает потому что был написан без знания реального кода).
- **R-C. NodeExecutionResult deprecation timing.** Если оставить `stateChanges` слишком долго — handlers продолжат писать "по-старому". Если убрать слишком рано — все 6 handlers сломаются. Compromise: deprecation warning + 1 sprint grace period.
- **R-D. effects[] mechanism.** В существующем коде `effects[]` используется для cross-domain (set_contact_attribute, set_contact_language). ADR State Writer Semantics это не описывает — но через ContactWriter эта функциональность сохраняется. Нужно явно migrate effects → direct ContactWriter.write() в каждом handler.
- **R-E. flow_logs schema vs new history.** `flow_logs.state_changes` колонка — артефакт старой архитектуры. Новая `flow_session_history` — opt-in структурированный лог. Сосуществуют, но запутывают. Долгосрочно нужен ADR раздел "logging surface consolidation".

### 5.2 Решения, которые нужно зафиксировать **до** старта Phase A

- **D-1.** Сохраняем ли существующий `NodeHandlerInterface` подпись (`type()` non-static, `label()`/`category()`/`configSchema()` присутствуют)?
  - Recommended: **да, сохраняем.** ADR не противоречит, это просто builder-side метаинформация.
- **D-2.** Wrapper-strategy для StateReader/Writer: foundation `ScopedStateReaderInterface` оборачивает Core `StateReader`?
  - Recommended: **да.** Foundation contract — public API для plugins, Core implementation — internal с богатым `WriteContext`/`Policy`.
- **D-3.** `effects[]` migration path?
  - Recommended: **deprecate after handlers migrated to direct writer**. Engine продолжает обрабатывать `effects[]` для legacy handlers до конца Phase B, потом удаляется.
- **D-4.** `Condition` → `Branch`: backward compat или новый node type?
  - Recommended: **расширить `Condition` до multi-case через `version: 2`**. Type=`condition` в snapshot остаётся; v1 = boolean, v2 = multi-case (`branch` semantics). UI builder обновляется на v2 для новых flows.
  - Альтернатива: новый type `branch`, v1 deprecated. Чище, но миграция flows.
- **D-5.** `SetAttribute` → `Assign`: аналогично выше.
- **D-6.** `Webhook` → `Call`: аналогично выше. Webhook может стать v2 с pluggable transport.
- **D-7.** `MessageSenderInterface` унификация — keep foundation или Core?
  - Recommended: **foundation как public API, Core implements**. Удаляем Core interface, мигрируем callers.

### 5.3 Что надо обсудить с пользователем явно перед стартом кодинга

1. Принимает ли он 6–8 недельную оценку вместо первоначально подразумеваемых 3 спринтов?
2. Согласен ли с D-1..D-7 рекомендациями?
3. Хочет ли отдельный "ADR Brownfield Migration Strategy" документ который зафиксирует D-1..D-7 + RA..RE?
4. Refactor existing handlers — его команда или один разработчик последовательно?
5. Можно ли отложить subflow (Phase C-4) на дополнительный sprint после релиза V1 без него? (V1 без subflow — всё ещё полезный продукт)

---

## 6. Outcome of this audit

- **План 11 переименован в "originally proposed" / "speculative"**; реальный план — Phase A–E здесь.
- Перед стартом любого кода нужно решение по D-1..D-7 (отдельный ADR Brownfield Migration Strategy опционален).
- Нет блокирующих проблем: все 4 ADR работают, но требуют адаптации к существующему коду через миграционный путь, не green-field build.

---

## Связано с

- [[implementation-plan]] — план реализации
- [[07-versioning]] — версионирование handlers
- [[_snapshot-v1.0]] — snapshot v1.0
