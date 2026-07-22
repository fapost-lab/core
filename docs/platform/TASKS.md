# FAPost Core — Задачи

Единый трекер статусов реализации. Для навигации по документам — [[INDEX]].

> **Легенда:** `- [x]` реализовано · `- [-]` частично / 🚧 · `- [ ]` предстоит

---

## 🏗 Платформа (Этап 1)

- [x] Структура проекта, базовые providers (`AppServiceProvider`, `DomainServiceProvider`)
- [x] Multi-tenancy: `TenantContext`, переключение схем, миграции, `TenantProvisioningService`
- [x] Flow engine: registry, execution loop, session persistence
- [x] Messaging pipeline: webhook → queue → worker → response (Telegram)
- [x] Filament admin: Tenants, Users, Assistants, Channels, Flows, Contacts
- [x] `platform:install` команда с seed first tenant

---

## ⚙️ Flow Engine

Спека: [[specs/flow-engine/README]]  
План реализации: [[plans/flow-engine/implementation-plan]]

### Foundation (Phase A)
- [x] **A-1** Foundation contracts (`ScopedStateReaderInterface`, `ContactWriterInterface`, `ExpressionEngineInterface`)
- [x] **A-2** Additive migrations (6 шт: subflow columns, history table, logging flag, commands)
- [x] **A-3** `ExpressionEngineRegistry` + `TemplateEngine` (13 тестов)
- [x] **A-4** `HistoryWriter` + `HistoryWriterFactory` (engine-driven instrumentation)
- [x] **A-5** `SessionLockManager` + heartbeat (Redis lock, Lua script)
- [x] **A-9** `BuiltinCommandsRegistry` + `CommandMatcher` (`/reset`, `/cancel`)

### Handlers rework (Phase B)
- [x] **B-1** `NodeExecutionContext` extension (expressionEngine, contactWriter, scopedStateReader)
- [x] **B-2.1** `SendMessageNodeHandler` refactor
- [x] **B-2.2** `InputNodeHandler` refactor + type extension
- [x] **B-2.3** `BranchNodeHandler` (type `branch`, multi-case) — [[specs/flow-engine/nodes/03-branch]]
- [x] **B-2.4** `AssignNodeHandler` (type `assign`, multi-op) — [[specs/flow-engine/nodes/05-assign]]
- [x] **B-2.5** `CallNodeHandler` (type `call`, pluggable transport) — [[specs/flow-engine/nodes/06-call]]
- [x] **B-2.6** `DelayNodeHandler` refactor
- [x] **B-3** FlowEngine + ContactWriter wiring; history instrumentation

### Net-new nodes (Phase C)
- [x] **C-2** `EndNodeHandler` (color-coded: success/cancelled/failed) — [[specs/flow-engine/nodes/10-end]]
- [x] **C-4** `SubflowNodeHandler` + CallGraphValidator + lifecycle — [[specs/flow-engine/nodes/08-subflow]]
- [x] **C-1** `EmitEventNodeHandler` (handler + validation + registry + event-chain flow start — `StartFlowFromEventJob` фанится на подписанные event-триггеры) — [[specs/flow-engine/nodes/07-emit-event]]
- [-] **C-3** `RagQueryNodeHandler` (handler + registry + validation есть; RAG Feature/storage/providers ещё не готовы) — [[specs/flow-engine/nodes/09-rag-query]]

### Routing pipeline (Phase D)
- [x] **D-1** `MessageRouter` + `DropPolicy` + `SessionStateRouter` (6 шагов)
- [x] **D-2** `TypingIndicatorService` integration
- [x] **D-3** `/reset` migration под `BuiltinCommandsRegistry` + force unlock

### Cleanup (Phase E)
- [x] Удалить `effects[]` из `NodeExecutionResult`
- [x] Удалить `applyEffects()` из FlowEngine
- [x] phpat enforcement rules (`composer run test:arch`; PHPat rules live in `tests/Architecture` and run via `phpstan.neon`)

---

## 🔁 Ноды (по приоритету)

Спеки: [[specs/flow-engine/nodes/README]]

### P0 — готово ✅
- [x] `send_message` — [[specs/flow-engine/nodes/01-send-message]]
- [x] `input` — [[specs/flow-engine/nodes/02-input]]
- [x] `condition` / `branch` — [[specs/flow-engine/nodes/03-branch]]
- [x] `end` — [[specs/flow-engine/nodes/10-end]]

### P1 — готово ✅
- [x] `delay` — [[specs/flow-engine/nodes/04-delay]]
- [x] `assign` — [[specs/flow-engine/nodes/05-assign]]
- [x] `call` — [[specs/flow-engine/nodes/06-call]]
- [x] `notify` (staff + contacts) — [[specs/flow-engine/nodes/notify]]
- [x] `set_tag` — [[specs/flow-engine/nodes/set-tag]]
- [x] `auth_request` — [[specs/flow-engine/nodes/auth-request]]

### P2
- [x] `subflow` — [[specs/flow-engine/nodes/08-subflow]]
- [x] `loop` + `loop_end` — [[specs/flow-engine/nodes/11-loop]]
  - [x] Backend: `LoopNodeHandler`, `LoopEndNodeHandler`, array variables, circular buffer
  - [x] Builder: `FlowLoopCard`, `LoopConfig.vue`, auto-managed `loop_end`, «Store as list»
  - [x] 8.1 Reachability (`ValidateFlowService::validateLoopReachesLoopEnd` — loop без достижимого `loop_end` → publish error `loop_missing_loop_end`)
  - [x] 7.2 Properties-конфликты между flow (array `item_type` drift теперь блокирует publish — `variable_properties_conflict`; `max_size` не в node config, остаётся registry-only)
  - [x] `.length` резолвер для array-переменных в условиях (`OperandResolver` — `contact.photos.length` → count)
  - [x] Budget: `flow.execution.max_iterations` + publish-error на literal count > 100
  - Остаток (V1.x, warning-only): 8.5 (while condition не меняется), 8.7 (static number check), UI редактирования `max_size`, `{{…length}}` в TemplateEngine
- [x] `emit_event` — event-chain start завершён: `DispatchFlowTriggerEventJob` теперь фанит `StartFlowFromEventJob` на каждый подписанный event-триггер (было — только лог); flow стартует для emitting-контакта, payload доступен как `flow.event.*`; выровнен формат имени события (trigger `event_name` теперь принимает dotted/mixed-case как emit `event_type`) — [[specs/flow-engine/nodes/07-emit-event]]
- [-] `rag_query` — handler/registry/validation готовы; Feature: RAG ещё предстоит — [[specs/flow-engine/nodes/09-rag-query]]

### P3
- [ ] `comment` (builder-only аннотация, без NodeHandler)

---

## 🖼 Builder (Vue)

Спеки: [[specs/builder/page-structure]]

### Schema renderer
- [x] Field-типы: `json`, `state-picker`, `object`, `key-value`, `object-array`, `flow-picker`, `enum-cards`
- [x] `help`, `required`, schema defaults, `visible_when`, inline-валидаторы
- [x] Секции с иконками, `AccordionSection`
- [x] Fluent API `Schema` / `XxxField::make()` — все Core-handler'ы мигрированы
- [x] Vendor glob (ADR-06): `vendorComponents.ts`, `vendorComponentName.ts` + vitest

### Config overrides (Core)
- [x] `SendMessageConfig.vue`
- [x] `InputConfig.vue`
- [x] `ConditionConfig.vue` / `BranchConfig.vue`
- [x] `AssignConfig.vue` (multi-operations)
- [x] `CallConfig.vue`
- [x] `SetTagConfig.vue`
- [x] `NotifyConfig.vue`
- [x] `AuthRequestConfig.vue`
- [x] `LoopConfig.vue`
- [x] `subflow`, `end` — schema-driven (без bespoke override)

### Variable storage
- [x] `VariableStorageEditor.vue` (StorageRadio + GroupSelect + inline-create)
- [x] `variableCompiler.ts` (compile/decompile + legacy)
- [x] Variable Picker rework (search, accordion, source icons, auto-flip popover)
- [x] Branch `ConditionOperandPicker.vue`
- [x] `VariableType`: Array, Boolean + `VariableCoercer`
- [x] `CacheBackedVariableSchemaRegistry` + cross-flow type conflict detection (type conflict + array `item_type` conflict блокируют publish)

### UX
- [x] Dirty indicator (●) + `beforeunload` guard
- [x] `useInsertAtCursor` в TextField / Textarea / StatePickerField
- [x] End node color-coding (success → зелёный, cancelled → амбер, failed → красный)
- [x] Flow Content Manager (многоязычный редактор контента, отдельная вкладка)
- [ ] `comment` нода на канвасе (P3)

---

## 🌍 Мультиязычность

Спека: [[architecture/adr/15-multilingual]]

- [x] `LanguageResolverInterface` + chain (session → contact → assistant → tenant fallback)
- [x] `SystemTranslationCatalogInterface` + `InMemorySystemTranslationCatalog`
- [x] `CoreSystemTranslations::seed()` (en/ru/uk ключи для commands, errors, buttons)
- [x] `CachedContentTranslator` (3-layer chain: assistant override → tenant override → catalog → key)
- [x] `tenant_translations` table (только overrides)
- [x] Filament UI: TenantTranslations + AssistantTranslations (tabbed, catalog picker, Reset action)
- [x] Locale-aware assistant fields (`fallback_message`, `busy_message`, commands JSON)
- [x] Flow Content Manager (отдельная вкладка builder'а)

---

## 🔐 Авторизация

Спека: [[specs/auth/00-overview]]

- [x] Granular permissions (Permission enum + `label()`, `isSensitive()`, `group()`)
- [x] Policy classes: Flow, FlowSession, FlowLog, Contact, Assistant
- [x] Roles UI: descriptions, sensitive marker, 2-column layout
- [x] `FlowAccessPolicy` + `flow_definitions.is_public` gate в `FlowOrchestrator`

---

## 📡 Messaging

### Webhook pipeline
- [x] Webhook routing: `/webhook/{channel}/{public_hash}` → Redis → tenant context
- [x] `IncomingMessageJob` → `MessageRouter` → `FlowOrchestrator`
- [x] `MessageSender` → `TelegramSender`
- [x] `ChannelObserver` удалён, webhook handling consolidation
- [x] **D-1** MessageRouter полный рефакторинг (6-шаговый pipeline)
- [x] **D-2** TypingIndicatorService

### Conversation Logging (новый домен)
Спека: [[specs/messaging/conversation-logging]]

- [x] `Conversation` агрегат + `conversation_messages` (monthly partitions, pgsql; sqlite fallback для тестов)
- [x] Port-интерфейсы: `ConversationLoggerInterface`, `ConversationStoreInterface` (`ConversationReaderInterface` — с Filament-фазой)
- [x] `MessageLogEntry` DTO + `ConversationRef`, enums (direction/sender/origin/status/content-type)
- [x] `PersistConversationMessageJob` + `UpdateConversationDeliveryStatusJob` (очередь `messaging.logging`, добавлена в Horizon low-group)
- [x] Capture-site в `IncomingMessageJob` (inbound, до router, идемпотентно по `update_id`) + `ConversationCaptureFactory`
- [x] Postgres driver (`PostgresConversationStore` + `ConversationPartitionManager`), провайдер по config
- [x] GDPR cascade delete (contacts/assistants/channels → conversations `cascadeOnDelete`)
- [x] Тесты: store (идемпотентность/агрегат/status), logger (dispatch/enabled), capture factory (11 unit)
- [x] **Фаза 5 — outbound capture** в едином funnel `MessageSender::send()` (только delivered); metadata обогащается в `FlowMessageSender` (origin=flow) и `SendContactNotificationJob` (origin=notify); `forOutbound()` в capture-factory; binding `MessageSender` → `scoped` (Octane-safe)
- [x] **Фаза 7 — Filament chat viewer** (assistant-панель, read-only): `ConversationResource` + `ConversationsTable` (inbox-лента) + `ViewConversation` (custom Page, blade-пузыри inbound/outbound, media/keyboard/delivery-status); lang en/ru/uk; scope по `assistant_id`
- [x] **Фаза 4a — inbound media**: `FetchConversationMediaJob` (queue `messaging.logging`) поверх `MediaIngestor::ingestFromChannel(..., source: Conversation)`; сообщение пишется сразу с media-дескриптором `status:pending`, джоба досоздаёт `media_file_id` (`status:ready`) либо `status:failed` с сохранением `provider_file_id`; `MediaSource::Conversation` + фильтр в `MediaService::listFolderContents` (не засоряет медиа-библиотеку); `appendMessage` возвращает id, `updateMessageMedia()` в store
- [x] Outbound media через upload-as-send (`FlowMessageSender` `alreadyDelivered`) теперь логируется — `buildOutboundMessage()` + `captureOutbound()` в самом `FlowMessageSender` (единственный путь мимо `MessageSender`)
- [ ] `ConversationReaderInterface` (Фаза 8, при переходе на ClickHouse; сейчас Filament читает Postgres-Eloquent — документированный trade-off §10)

> **Вне скоупа (отдельная фаза после запуска продукта):** delivery-status (read-receipts). Provider-agnostic плумбинг
> `updateDeliveryStatus` → `UpdateConversationDeliveryStatusJob` → `store.updateStatus` уже есть и покрыт тестами, но
> «спит»: Telegram не даёт статусов доставки, а WhatsApp-канал вынесен в отдельную пост-запусковую фазу целиком.

---

## 📢 Broadcasting (Feature)

- [ ] `broadcasts` / `broadcast_recipients` модели + статусы (draft → running → completed)
- [x] Filament UI: TenantSettings таб Broadcasts
- [-] `BroadcastSendJob` → `messaging.broadcast` очередь (низкоуровневый fan-out job есть; per-recipient bookkeeping нет)
- [ ] Backpressure: проверка длины `transactional` очереди перед отправкой
- [ ] Rate limiting per `(bot_id, chat_id)` превентивно

---

## 🤖 RAG (Feature)

- [ ] `knowledge_bases` table + миграция
- [x] `RagAdapterRegistry`
- [x] `RagQueryNodeHandler` — [[specs/flow-engine/nodes/09-rag-query]]
- [x] RAG validation в `ValidateFlowService`
- [ ] RAG provider adapter registration
- [ ] Filament UI: Knowledge Bases resource

---

## 🏠 Contact Domain

- [x] `Contact` модель: `id`, `channel`, `language` (canonical), `meta`, `attributes`
- [x] `contact_tags` + `ContactTagRepository` (set_tag нода)
- [x] `contact_groups` + `contact_group_members`
- [x] `ContactResource` Filament (group sections, «Manage tags» action)
- [ ] `contact_segments` (rules JSON, `cached_count`)
- [ ] `ContactSegmentResolver` (для Broadcasting)

---

## 🔧 Infrastructure / Tech Debt

- [x] phpat enforcement (`composer run test:arch`; rules exist under `tests/Architecture` and run via PHPStan)
- [ ] `flow_active_node_stats` — статистика активных нод для safe handler removal — [[specs/flow-engine/node-usage-statistics]]
- [x] End-to-end integration тесты: subflow lifecycle — success/failed (были) + **cancelled** (добавлен, `SubflowLifecycleTest`); timeout покрыт `SubflowTimeoutSweeperTest` (нет отдельного `timeout`-статуса — таймаут = child `failed` + parent `failed`/`expired`)
- [-] Concurrency hardening тесты: **optimistic-lock retry** (`FlowOrchestratorRetryTest` — retry-succeeds / exhaust-rethrow) + **engine_lock_timeout** drop-outcome (`MessageRouterTest`) добавлены; distributed lock покрыт unit-тестами (`FlowExecutionGuardTest`, `SessionLockManagerTest`, `LockAcquisitionPolicyTest`). Не покрыто: real-Redis integration (моки), **heartbeat** (`LockHeartbeat` dead-wired — нет каллеров/шедулера; live keep-alive — это typing-heartbeat, не lock)
- [x] `/reset` migration под `BuiltinCommandsRegistry` с force unlock (D-3)
- [x] Routing pipeline 6 шагов end-to-end с typing indicator (D-1+D-2)

---

## Связано с

- [[ROADMAP]] — дорожная карта платформы
- [[INDEX]] — навигация по документам
- [[PROJECT]] — описание проекта
