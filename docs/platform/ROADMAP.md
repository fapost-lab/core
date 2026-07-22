# FAPost Core — Дорожная карта

Product vision от текущего состояния до SaaS-оболочки, Solutions и Inbox.  
Детализация по задачам — [[TASKS]]. Навигация по документам — [[INDEX]].

> **Статусы:** ✅ завершено · 🔄 в работе / частично · ⏳ следующее · 📋 запланировано · 🔮 будущее

---

## ✅ Milestone 1 — Platform Core

> *Фундамент: multi-tenancy, flow engine V1, messaging pipeline, admin UI.*

**Когда:** закрыт (июнь 2026)

**Что готово:**
- Multi-tenancy: `TenantContext`, schema-per-tenant, `TenantProvisioningService`
- Flow engine V1: registry, execution loop, session, все P0 + P1 ноды (8 шт)
- Messaging pipeline: Telegram webhook → queue → worker → response
- Filament admin: Users / Roles / Assistants / Channels / Flows / Contacts
- Builder: schema renderer, Config overrides, Variable storage, Variable Picker
- Авторизация: Policy classes, granular permissions, Roles UI
- Мультиязычность: `LanguageResolver`, `SystemTranslationCatalog`, 3-layer translator
- `platform:install`, `BuiltinCommandsRegistry` (`/reset`, `/cancel`)

**Тесты:** 651+ проходят.

---

## 🔄 Milestone 2 — Runtime Hardening

> *Продакшн-готовность: полный pipeline обработки, конкурентность, CI-правила, loop завершение.*

**Когда:** текущий

**Что предстоит:**

### Loop node (остатки)
- [ ] 8.1 Reachability — предупреждения о потенциально бесконечных циклах в валидаторе  
- [ ] 7.2 Properties-конфликты: type conflict уже блокируется, array `properties` drift ещё нужно surfaced как warning/error  
- [ ] `.length` резолвер для array-переменных в условиях branch  
- [x] Budget: `flow.execution.max_iterations` + publish-error на literal count > 100  
Спека: [[specs/flow-engine/nodes/11-loop]]

### Routing pipeline (Phase D)
- [x] **D-1** `MessageRouter` 6-шаговый pipeline + `DropPolicy` + `SessionStateRouter`  
- [x] **D-2** `TypingIndicatorService` (индикатор печатания во время выполнения)  
- [x] **D-3** `/reset` migration под `BuiltinCommandsRegistry` с force unlock  
Спека: [[architecture/adr/09-message-routing-concurrency]]

### CI enforcement
- [x] phpat правила: Migration Isolation, Handler Version Contract, Dependency Direction (`composer run test:arch`)  
- [ ] Concurrency hardening тесты: distributed lock, heartbeat, optimistic lock retry

### Cleanup (Phase E)
- [x] Удалить `effects[]` из `NodeExecutionResult`
- [x] Удалить deprecated `applyEffects()` из FlowEngine

---

## ⏳ Milestone 3 — Conversation Logging

> *Транскрипт переписки: фундамент для Inbox, live-chat, human takeover.*

**Когда:** следующий после M2

**Почему важно:** без хранения диалогов невозможен ни Inbox, ни передача оператору, ни аудит. Спроектировано с портами, чтобы в будущем менять backend (Postgres → ClickHouse).

Спека: [[specs/messaging/conversation-logging]]

**Что предстоит:**

### Домен Conversation
- [ ] Миграции: `conversations` (агрегат), `conversation_messages` (monthly partitions)
- [ ] `ConversationLoggerInterface` — write port  
- [ ] `ConversationStoreInterface` — driver port  
- [ ] `ConversationReaderInterface` — read port  
- [ ] `MessageLogEntry` DTO  
- [ ] `EloquentConversationStore` — Postgres-драйвер

### Capture pipeline
- [ ] `PersistConversationMessageJob` (очередь `messaging.logging`)  
- [ ] Capture inbound: `IncomingMessageJob` (до router, ловит дропнутые сообщения)  
- [ ] Capture outbound: `MessageSender::send()` (после `DeliveryResult`)  
- [ ] Capture broadcast: `BroadcastSendJob`

### Quality
- [ ] GDPR cascade: contacts → conversations → messages → media refcount  
- [ ] Unit тесты per driver; integration тесты capture pipeline  
- [ ] `MediaSource::Conversation` (open question §14 спеки)

---

## 📋 Milestone 4 — Broadcasting & Segments

> *Управляемые рассылки + динамические сегменты контактов.*

**Когда:** параллельно или после M3

**Что предстоит:**

### Broadcasting
- [ ] `broadcasts` / `broadcast_recipients` модели + статусы рассылок
- [ ] Backpressure: проверка длины `transactional` очереди в `BroadcastSendJob`  
- [ ] Rate limiting per `(bot_id, chat_id)` превентивно (не по ошибке)  
- [ ] Аналитика рассылок в Filament (статусы, stats JSON)

### Contact Segments
- [ ] `contact_segments` таблица (rules JSON, `cached_count`)  
- [ ] `ContactSegmentResolver` — вычисление выборки по rules  
- [ ] Filament UI: Segments resource  
- [ ] Broadcasting: target_type `segment` через `ContactSegmentResolver`

---

## 📋 Milestone 5 — RAG Feature

> *Интеграция с базами знаний: поиск по документам внутри flow.*

**Когда:** после M2 (runtime registry/handler уже есть; осталось storage/providers/UI)

Спека: [[specs/flow-engine/nodes/09-rag-query]]

**Что предстоит:**
- [ ] `knowledge_bases` таблица + миграция + Filament resource  
- [x] `RagAdapterRegistry` (plug-in адаптеры провайдеров)  
- [x] `RagQueryNodeHandler` (type `rag_query`) — пишет `rag.*` в state  
- [x] RAG validation в `ValidateFlowService` (config shape/provider runtime guard; проверка knowledge_base ждёт storage)  
- [ ] `StructuredRagResult` DTO (found, confidence, answer, intent, metadata)  
- [ ] Первый адаптер (OpenAI embeddings / pgvector или внешний провайдер)

---

## 📋 Milestone 6 — Call Transport & Event Chains

> *`emit_event` нода + полная цепочка flow-to-flow через события.*

**Когда:** после M2

Спека: [[specs/flow-engine/nodes/07-emit-event]]

**Что предстоит:**
- [x] `EmitEventNodeHandler` (type `emit_event`)  
- [x] `TenantEventRepository` + `ResolveEventTriggersService`  
- [ ] `event` триггер — подписка flow на событие с опциональной задержкой (частично: resolution/registry есть; start pipeline не завершён)  
- [ ] End-to-end тест: flow A → emit → delay → flow B start  

> `emit_event` закрывает superseded `go_to_flow` сценарий «jump без возврата».

---

## 📋 Milestone 7 — Solutions Framework

> *Механизм расширения платформы внешними пакетами (Solutions).*

**Когда:** когда есть первый реальный Solution под разработку (HR?)

Документ: [[architecture/adr/05-foundation-contract-package]], [[architecture/platform/12-solutions-modules]]

**Что предстоит:**
- [ ] `AbstractSolutionServiceProvider` lifecycle test suite  
- [ ] `SolutionManifest` validation при `platform:update`  
- [ ] `vendor:publish` для Solution Vue компонентов + `npm run build` шаг  
- [ ] `ActionHandlerRegistry` — регистрация `hr.*`, `crm.*` handler'ов  
- [ ] Первый Solution: **FAPost HR** (`hr.sync_employee`, `hr.create_assessment`)  
- [ ] Filament: Solution activation UI (install/activate/deactivate)  
- [ ] E2E тест: Solution installs → handler available → call node executes

---

## 📋 Milestone 8 — Inbox / Live Chat

> *Операторский интерфейс: просмотр диалогов, ответы, передача оператору.*

**Когда:** после M3 (требует Conversation Logging)

**Что предстоит:**
- [ ] Filament: `ConversationResource` — список тредов с фильтрами (статус, ассистент, тег)  
- [ ] Filament: Thread detail — хронологический транскрипт + reply input  
- [ ] `human_takeover` механизм: нода или триггер → `conversation.status = human`  
- [ ] Routing rule: `conversation.status = human` → сообщения идут оператору, не flow  
- [ ] Staff assignment (conversation owner, `user_assistants` scope)  
- [ ] Filament: Unread counter / notification badge  
- [ ] Передача обратно flow: operator closes → resume или start new flow  
- [ ] Real-time (polling или Pusher/WebSocket — решение по выбору стека)

---

## 📋 Milestone 9 — Multi-channel Expansion

> *WhatsApp + другие платформы помимо Telegram.*

**Когда:** по запросу / коммерческому приоритету

**Что предстоит:**
- [ ] `WhatsAppAdapter` (`ChannelInterface` impl)  
- [ ] WhatsApp-специфичные content types (template messages, buttons)  
- [ ] Channel test harness (общий для всех адаптеров)  
- [ ] `channel_type` в `IncomingMessage` / `OutboundMessage` — уже заложен

---

## 🔮 Milestone 10 — SaaS Shell

> *Многоарендный SaaS: биллинг, план-управление, onboarding.*

**Когда:** отдельный репо `fapost-saas`, после стабилизации Core

Документ: [[architecture/platform/02-saas-shell]]

**Ключевые концепты:**
- Landlord DB: `tenants`, `plans`, `subscriptions`  
- Billing integration (Stripe / LiqPay / локальный провайдер)  
- `plan → tenant_activations`: plan определяет доступные Features + Solutions  
- Onboarding flow (создание tenant → provision → activation)  
- Usage analytics (analytics_events → billing meters)  
- Portal: tenant self-service (смена плана, инвойсы, members)

> Core не знает о SaaS. SaaS-оболочка — обёртка поверх Core API, без изменений в этом репо.

---

## 🔮 Milestone 11 — Ecosystem / Plugin Marketplace

> *Сторонние плагины без пересборки платформы.*

**Когда:** после M7 (Solutions framework)

Документ: [[architecture/adr/06-frontend-extension-boundary]]

**Ключевые концепты:**
- `PluginRegistry` (отдельный от `ActivationRegistry`)  
- Runtime install без deploy: JSON flow templates, `DataAccessorInterface`, queue jobs  
- Plugin store UI: browse → install → activate per tenant  
- Sandbox: plugins не получают доступ к landlord DB, не регистрируют worker pools  
- Developer SDK документация + примеры

---

## Зависимости между milestone'ами

```
M1 (done) ──► M2 ──► M3 ──► M8 (Inbox)
               │
               ├──► M4 (Broadcasting)
               ├──► M5 (RAG)
               └──► M6 (emit_event) ──► M7 (Solutions) ──► M11 (Plugins)
                                                │
                                                └──► M10 (SaaS)  ─ отдельный репо

M9 (WhatsApp) — независим, параллельно с любым M2+
```

---

## Критерии «Production Ready» (после M2–M3)

- [ ] Полный 6-шаговый routing pipeline end-to-end  
- [ ] Все диалоги логируются (inbound + outbound + broadcast)  
- [ ] Concurrency тесты: lock, heartbeat, optimistic retry  
- [x] phpat CI правила enforced через `composer run test:arch`  
- [ ] GDPR cascade delete проверен  
- [ ] Load test: 100 concurrent sessions без state leakage (Octane scope)

---

## Связано с

- [[TASKS]] — детальные задачи реализации
- [[INDEX]] — навигация по документам
- [[PROJECT]] — описание проекта
- [[plans/flow-engine/implementation-plan]] — план реализации flow engine
