# FaPost Core — Задачи

Запись того, что уже реализовано, по разделам. Для навигации по документам — [[INDEX]].

> **Не трекер предстоящего.** Открытые направления ведутся спеками Jig под `.ai/specs/`
> (`.ai/scripts/jig spec list`), отдельные мелкие работы — задачами (`.ai/scripts/jig task list --all`).
> Оставшиеся здесь `- [ ]` — это ссылки на ту спеку или задачу, где пункт живёт на самом деле.

> **Легенда:** `- [x]` реализовано · `- [-]` частично / 🚧 · `- [ ]` ведётся в Jig, ссылка в строке

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
- [ ] `form` — сбор данных через веб-форму (TMA / hosted) вместо цепочки `input` — спека `.ai/specs/forms-data-collection/`

### P3
- [-] `comment` (builder-only аннотация): backend готов — палитра (`NodeTypesController`), skip в валидации, strip-at-publish (`AnnotationNodeTypes`); остаётся Vue-рендеринг ноды на канвасе — задача Jig `comment-node-canvas`

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
- [-] `comment` нода на канвасе (P3) — backend-энейблмент готов (палитра/валидация/strip); Vue-карточка на канвасе — задача Jig `comment-node-canvas`

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
- [x] **Фаза 5 — outbound capture** в едином funnel `MessageSender::send()` (только delivered); metadata обогащается в `FlowMessageSender` (origin=flow) и `SendContactNotificationJob` (origin=notify); `forOutbound()` в capture-factory; binding `MessageSender` → `scoped` (безопасно для долгоживущих воркеров)
- [x] **Фаза 7 — Filament chat viewer** (assistant-панель, read-only): `ConversationResource` + `ConversationsTable` (inbox-лента) + `ViewConversation` (custom Page, blade-пузыри inbound/outbound, media/keyboard/delivery-status); lang en/ru/uk; scope по `assistant_id`
- [x] **Фаза 4a — inbound media**: `FetchConversationMediaJob` (queue `messaging.logging`) поверх `MediaIngestor::ingestFromChannel(..., source: Conversation)`; сообщение пишется сразу с media-дескриптором `status:pending`, джоба досоздаёт `media_file_id` (`status:ready`) либо `status:failed` с сохранением `provider_file_id`; `MediaSource::Conversation` + фильтр в `MediaService::listFolderContents` (не засоряет медиа-библиотеку); `appendMessage` возвращает id, `updateMessageMedia()` в store
- [x] Outbound media через upload-as-send (`FlowMessageSender` `alreadyDelivered`) теперь логируется — `buildOutboundMessage()` + `captureOutbound()` в самом `FlowMessageSender` (единственный путь мимо `MessageSender`)
- [ ] `ConversationReaderInterface` (Фаза 8, при переходе на ClickHouse; сейчас Filament читает Postgres-Eloquent — документированный trade-off §10) — задача Jig `conversation-reader-interface`

> **Вне скоупа (отдельная фаза после запуска продукта):** delivery-status (read-receipts). Provider-agnostic плумбинг
> `updateDeliveryStatus` → `UpdateConversationDeliveryStatusJob` → `store.updateStatus` уже есть и покрыт тестами, но
> «спит»: Telegram не даёт статусов доставки, а других каналов в планах сейчас нет (M9 удалён).

---

## 💬 Inbox / Live Chat (M8) — закрыт

Детали и обоснования решений — [[ROADMAP]] § Milestone 8.

### Безопасность
- [x] `Permission::ViewConversations` / `ReplyConversations` (обе sensitive), группа `conversations`, раздача ролям
- [x] `ConversationPolicy` + регистрация в провайдере; ресурс спрашивает политику вместо `instanceof User`
- [x] Кастомная страница треда авторизуется явно — Filament покрывает только встроенные страницы ресурса

### Takeover
- [x] `ConversationOwner` (`bot` / `staff`) поверх зарезервированных `owner_type` / `owner_staff_user_id`
- [x] `ConversationOwnershipInterface` + Eloquent-реализация, отдельно от append-only store-порта
- [x] Врезка в `MessageRouter` шагом 4a — до классификации состояния сессии; `/reset` по-прежнему раньше
- [x] Действия «Взять диалог» / «Вернуть боту»

### Ответ оператора
- [x] `ConversationReplyService` через общий `MessageSender::send()`, на канал самого треда
- [x] `senderType = Staff` выводится из `origin`, а не из отдельного ключа metadata
- [x] Ответ уходит без `parse_mode`: под HTML обычное «R&D» ломает доставку целиком

### Тред и лента
- [x] `markRead` при открытии треда (после проверки прав), badge непрочитанных в навигации
- [x] Пагинация треда (50 + догрузка) вместо неограниченной выборки
- [x] Медиа через `MediaService::signedUrl()`, состояния `pending` / `failed`
- [x] Polling 30 s (real-time стека в проекте нет — `config/broadcasting.php` отсутствует)
- [x] `FallbackMessageService` пишется в транскрипт: раньше busy/fallback-ответы молча выпадали из лога

---

## 📝 Forms / Data Collection (M9)

Перенесено в спеку **`.ai/specs/forms-data-collection/`**: решения, четыре фазы и открытые вопросы
живут там, чекбоксов по этому направлению здесь больше нет. В коде пока только каркас TMA
(`routes/tma.php`, `resources/js/tma`), `TmaFormController` и `TmaAuthMiddleware` — заглушки.

---

## 📢 Broadcasting (Feature)

- [x] `broadcasts` / `broadcast_recipients` модели + статусы (draft → running → completed/failed/cancelled) — новый домен `Broadcasting`
- [x] `RunBroadcastJob` (fan-out: резолв аудитории → materialize recipients → per-recipient send) + `SendBroadcastRecipientJob` (доставка + bookkeeping + counters + completion) на `messaging.broadcast`; `BroadcastDispatcher` (атомарный Draft→Running); `BroadcastRecipientResolver` (реюз notify-таргетинга All/Tags)
- [x] Per-recipient bookkeeping: `broadcast_recipients` (pending→sent/failed/skipped, provider_message_id), денормализованные счётчики на `broadcasts`; идемпотентно по статусу получателя; outbound логируется в транскрипт (origin=broadcast)
- [x] Backpressure: `RunBroadcastJob` откладывает запуск при переполнении `messaging.broadcast` (настройка `broadcast_backpressure`); rate-shaping fan-out по `broadcast_chunk_size` в секунду
- [x] Rate limiting per `(channel, chat)` — переиспользуется превентивный лимит `MessageSender`
- [x] Filament UI: полноценный **дашборд рассылок** (assistant-панель) — composer (name/message/audience All|Tags), lifecycle-таблица со статус-бейджами и прогрессом, confirmable **Send** + **Cancel**, edit/delete только в Draft; lang en/ru/uk
- [x] Filament UI: TenantSettings таб Broadcasts (настройки chunk_size / backpressure — теперь реально читаются)
- [x] **Мультиязычный контент рассылки**: `broadcasts.message` text → jsonb, cast `'message' => 'array'`, вкладки локалей через `LocalizedTextarea::tabs()`, нормализация карты + валидация непустого текста на `content_base_language` (раньше рассылка без текста молча уходила в `skipped` у всех получателей), lang en/ru/uk. Доставка не менялась — `SendBroadcastRecipientJob` уже звал `resolveField()`
- [x] Таргетинг по группам контактов — через `SegmentConditionType::Group`

---

## 🧊 RAG (Feature) — бэклог

Перенесено в спеку **`.ai/specs/rag-knowledge-bases/`**.

Что уже в коде: `RagAdapterRegistry`, `RagQueryNodeHandler` ([[specs/flow-engine/nodes/09-rag-query]])
и RAG-валидация в `ValidateFlowService`. Нода `rag_query` остаётся в палитре и падает на runtime
guard'е — осознанный долг до выбора провайдера эмбеддингов и хранилища векторов.

---

## 🏠 Contact Domain

- [x] `Contact` модель: `id`, `channel`, `language` (canonical), `meta`, `attributes`
- [x] `contact_tags` + `ContactTagRepository` (set_tag нода)
- [x] `contact_groups` + `contact_group_members`: миграции, модель `ContactGroup`, связь `Contact::groups()`, Filament-ресурс групп и назначение групп контакту
- [x] Условия сегмента по группам: `SegmentConditionType::Group` + `ContactSegmentResolver::applyGroup()` (операторы `in` / `not_in`)
- [x] **Дефект таргетинга закрыт:** нераспознанное условие (пустой список групп, пустой тег, битый dot-path, неизвестный тип) молча выбрасывалось, из-за чего сегмент под `match: all` становился «все контакты тенанта» вместо «никто» — рассылка ушла бы по всей базе. Теперь такое условие резолвится в «никто» (`matchNothing()`); сегмент без условий по-прежнему осознанно значит «все»
- [x] `ContactResource` Filament (group sections, «Manage tags» action)
- [x] `contact_segments` (rules JSON `{match, conditions[]}`, `cached_count`/`cached_count_at`) — модель + миграция
- [x] `ContactSegmentResolver` — компилирует rules в tenant-scoped Contact-запрос (условия: tag has/not_has, language/platform in/eq; all/any); `resolveContactIds` / `count` / `refreshCount`
- [x] Интеграция в Broadcasting: `BroadcastTarget::Segment` + `broadcasts.target_segment_id`; `BroadcastRecipientResolver` резолвит сегмент → пересечение с deliverable-контактами ассистента
- [x] Filament: `ContactSegmentResource` (assistant-панель, tenant-scoped) — rules-builder (match + Repeater условий), «Recount» action; segment-опция в composer'е рассылки; lang en/ru/uk
- [x] Условия по attributes-json (dot-path key, eq/ne/exists) — реализованы в `ContactSegmentResolver` + Filament

---

## 🔌 MCP Server (M12)

Перенесено в спеку **`.ai/specs/mcp-server/`**: пять фаз, архитектурные решения и открытые вопросы —
там. В коде нет ни домена `Mcp`, ни token-инфраструктуры (Sanctum не установлен).

---

## 🔧 Infrastructure / Tech Debt

- [x] phpat enforcement (`composer run test:arch`; rules exist under `tests/Architecture` and run via PHPStan)
- [x] **Статистика использования нод для safe handler removal.** Два среза без DDL: static — агрегат в PHP по `nodes` jsonb активных `flow_definitions`; runtime — портируемый `GROUP BY` по существующему `flow_logs`. Ключ — пара `type@version`, а не тип: реестр резолвит по `type@version`, и без версии вопрос «снять ли v1» не закрывается. Сверка с `NodeHandlerRegistry` даёт ноды без хендлера и типы, не используемые ни одним арендатором (пересечение, не объединение). `NodeUsageStatisticsService` + команда `flow:node-usage` (`--days/--tenant/--type/--json`), обходящая арендаторов через `TenantSwitcher`. SQL view из черновика отклонён (непортируем между pgsql/sqlite, объект схемы в каждой схеме, не отвечает на межарендаторский вопрос); rollup-таблица не делалась — это новая таблица и отдельное решение. Попутно в черновике спеки исправлены три ошибки: `logging_enabled` гейтит `flow_session_history`, а не `flow_logs`; delay-исполнения и idempotency-хиты логируются; статус `conflict` не пишется никогда. Тесты: `NodeUsageStatisticsServiceTest`, `NodeUsageCommandTest`. Спека: [[specs/flow-engine/node-usage-statistics]]
- [x] End-to-end integration тесты: subflow lifecycle — success/failed (были) + **cancelled** (добавлен, `SubflowLifecycleTest`); timeout покрыт `SubflowTimeoutSweeperTest` (нет отдельного `timeout`-статуса — таймаут = child `failed` + parent `failed`/`expired`)
- [x] Concurrency hardening тесты: **optimistic-lock retry** (`FlowOrchestratorRetryTest`) + **engine_lock_timeout** drop-outcome (`MessageRouterTest`) добавлены; distributed lock покрыт unit-тестами (`FlowExecutionGuardTest`, `SessionLockManagerTest`, `LockAcquisitionPolicyTest`). Real-Redis integration и heartbeat — в `tests/Feature/Redis` (группа `redis`)
- [x] **Консолидация блокировок + heartbeat (ADR-09).** Один lock на `(tenant, contact, assistant)` вместо двух вложенных с разными ключами. Новый `SessionLockRegistry` (scoped) — слот текущего захвата; `FlowExecutionGuard` переписан на `SessionLockManager`, стал ре-энтрантным и возвращает слот внешнему владельцу при вложенном вызове на другой scope; `FlowEngine::executeLoop()` продлевает TTL перед каждой нодой, при потере владения бросает `SessionLockLostException` → роутер отдаёт `dropped('lock_lost')`; параметры вынесены в `config/flow.php` (`lock.*`), пять хардкодов `30` убраны; удалены мёртвые `MessageRouter::tickHeartbeat()` и `buildLockKey()`. Тесты: `LockHeartbeatTest`, `SessionLockRegistryTest`, `FlowEngineSessionLockTest` + расширены `FlowExecutionGuardTest` / `MessageRouterTest`
- [x] Redis-integration suite: `tests/Feature/Redis` (группа `redis`, `composer run test:redis`) в общем прогоне, Redis из `.env` / CI-сервиса; заодно `ResumeTimedOutSendMessageNodeJob` и `StartFlowFromEventJob` переведены под `FlowExecutionGuard`
- [x] `/reset` migration под `BuiltinCommandsRegistry` с force unlock (D-3)
- [x] Routing pipeline 6 шагов end-to-end с typing indicator (D-1+D-2)

---

## Связано с

- `.ai/specs/` — открытые направления как спеки Jig (`.ai/scripts/jig spec list`)
- [[ROADMAP]] — история инженерных майлстоунов
- [[INDEX]] — навигация по документам
- [[PROJECT]] — описание проекта
