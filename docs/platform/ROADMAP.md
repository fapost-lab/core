# FAPost Core — Дорожная карта

Product vision от текущего состояния до SaaS-оболочки, Solutions и Inbox.  
Детализация по задачам — [[TASKS]]. Навигация по документам — [[INDEX]].

> **Статусы:** ✅ завершено · 🔄 в работе / частично · ⏳ следующее · 📋 запланировано · 🧊 бэклог · 🔮 будущее

---

## 🎯 Релизный трек: до передачи в тестирование

Цель — довести **M2 → M4 → M8**, выложить на сервер и передать в тестирование. Всё остальное ждёт.

| Milestone | Что осталось | Объём |
|---|---|---|
| M2 Runtime Hardening | консолидация двух реализаций лока + продление TTL; Redis-suite для тестов | средний, но начинается с архитектурного решения |
| M4 Broadcasting & Segments | мультиязычный контент рассылки; группы контактов | i18n — малый (доставка готова); группы — можно вынести за релиз |
| M8 Inbox / Live Chat | права доступа, takeover, ответ оператора, unread, пагинация, медиа, polling | наибольший |

**Два пункта требуют решения до начала работ:** (1) в M2 — свести `SessionLockManager` и `FlowExecutionGuard`
в одну реализацию или удалить мёртвую; (2) в M4 — разрешить расхождение между таблицей групп и Redis-кэшем
групп из спеки `05-group-storage`.

**Блокер безопасности:** inbox сейчас открыт любому аутентифицированному пользователю — прав на диалоги
в `Permission` enum нет вовсе. Закрыть до выкладки, см. § Milestone 8.

После выкладки: M12 (MCP Server) → M7 (Solutions) → M11. M5 (RAG) — в бэклоге.
M9 (Multi-channel / WhatsApp) удалён из планов.

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

**Когда:** текущий. Осталась одна незакрытая позиция — heartbeat (см. ниже).

### Loop node — закрыт ✅
- [x] 8.1 Reachability — `ValidateFlowService::validateLoopReachesLoopEnd`, publish-error `loop_missing_loop_end`  
- [x] 7.2 Properties-конфликты — array `item_type` drift блокирует publish (`variable_properties_conflict`)  
- [x] `.length` резолвер для array-переменных в условиях branch (`OperandResolver`)  
- [x] Budget: `flow.execution.max_iterations` + publish-error на literal count > 100  
Остаток вынесен в V1.x warning-only (8.5, 8.7, UI для `max_size`) — см. [[TASKS]].  
Спека: [[specs/flow-engine/nodes/11-loop]]

### Routing pipeline (Phase D)
- [x] **D-1** `MessageRouter` 6-шаговый pipeline + `DropPolicy` + `SessionStateRouter`  
- [x] **D-2** `TypingIndicatorService` (индикатор печатания во время выполнения)  
- [x] **D-3** `/reset` migration под `BuiltinCommandsRegistry` с force unlock  
Спека: [[architecture/adr/09-message-routing-concurrency]]

### CI enforcement
- [x] phpat правила: Migration Isolation, Handler Version Contract, Dependency Direction (`composer run test:arch`)  
- [-] Concurrency hardening: optimistic-lock retry и distributed lock покрыты (`FlowOrchestratorRetryTest`,
  `FlowExecutionGuardTest`, `SessionLockManagerTest`, `LockAcquisitionPolicyTest`); не закрыто — интеграция на живом
  Redis (сейчас моки)

### 🚧 Открытый блокер M2 — locking

Разведка по коду показала, что задача крупнее, чем «подключить heartbeat». В репозитории **две параллельные
несвязанные реализации блокировки**, и продлевать TTL сейчас нечему.

**Что есть по факту:**

| Реализация | Где | Ключ | extend |
|---|---|---|---|
| `SessionLockManager` + `LockHeartbeat` + `LockAcquisitionPolicy` + `LockScope`/`LockHandle` | `Domains/Flow/Concurrency/` | `session_lock:{tenant}:{contact}:{assistant}` | ✅ Lua `GET`+`EXPIRE` с проверкой токена |
| `FlowExecutionGuard` | `Infrastructure/Flow/` | тот же формат | ❌ фреймворковый `Lock` API не умеет |
| `MessageRouter` | `Domains/Flow/Routing/` | `session_lock:{tenant}:{platform}:{externalUserId}:{assistant}` | ❌ |

Весь `Concurrency/`-пакет **не используется в production-коде** — только регистрация в `FlowServiceProvider:319-321`
и unit-тесты. Реальный лок держат `MessageRouter` (внешний, `Cache::lock`) и `FlowExecutionGuard` (внутренний,
`LockProvider`) — то есть на одну сессию берутся два вложенных лока **с разным форматом ключа**, и ни один из них
не продлевается за всё время исполнения flow.

- [ ] **Решение по консолидации (первым делом, архитектурное).** Либо `FlowExecutionGuard` переводится на
  `SessionLockManager` и получает `extend()`, либо `Concurrency/`-пакет удаляется как мёртвый. Полумеры оставят
  два лока. Заодно свести формат ключа к одному — сейчас внешний и внутренний локи защищают формально разные
  ресурсы.
- [ ] **Точка тика.** `FlowEngine::executeLoop()` строка ~364 — перед каждым `handler->execute()` уже дёргается
  `typingHeartbeat->current()?->refresh()`. Продление лока ложится туда же симметрично. Требуется пробросить
  `LockHandle` в контекст движка (сейчас он не доходит).
- [ ] **Реакция на потерю владения.** `extend()` возвращает `false` → по ADR-09 исполнение обязано прерваться;
  оптимистичный лок на `flow_sessions.version` — вторая линия, не первая.
- [ ] **Тик привязан к итерации, а не ко времени.** Одна медленная нода (`call`, `rag_query`) = один тик.
  Нужен либо тик внутри долгих хендлеров, либо TTL с запасом на самый медленный узел.
- [ ] **Конфиг вместо хардкода.** TTL 30 s захардкожен в 5 местах (`SessionLockManager:35`,
  `LockAcquisitionPolicy:22`, `FlowExecutionGuard:16`, `FlowServiceProvider:389`, `MessageRouter:47`).
  В `config/flow.php` сейчас единственный ключ — `execution.max_iterations`.
- [ ] **Согласовать TTL с Horizon.** Воркер `high` (`flow.execution`) имеет `timeout: 60` при lock TTL 30 —
  сессия может остаться без лока, пока джоба ещё выполняется.
- [ ] **Пути в обход локов.** `ResumeTimedOutSendMessageNodeJob:48` зовёт `engine->resume()` мимо `MessageRouter`
  и мимо `FlowExecutionGuard`. Также `SubflowStarterService:143` и `DefaultSubflowResumer:84` входят в движок
  внутри уже удерживаемого родительского лока.
- [ ] Мёртвый хук `MessageRouter::tickHeartbeat()` (стр. 66-74) — удалить или задействовать.
- [ ] Тест на `LockHeartbeat` — сейчас его нет ни одного.

### Интеграционные тесты на живом Redis
- [ ] Инфраструктуры нет вообще: в `phpunit.xml` два suite (`Unit`, `Feature`), `CACHE_STORE=array`,
  `QUEUE_CONNECTION=sync`, переменных `REDIS_*` нет, `.env.testing` отсутствует. Нужен третий suite поверх
  devilbox-Redis + flush-хук по аналогии с `RefreshDatabase`.
- [ ] Комментарий в `SessionLockManagerTest:14-17` обещает feature-тесты на реальном Redis — их в репозитории нет.

### Cleanup (Phase E)
- [x] Удалить `effects[]` из `NodeExecutionResult`
- [x] Удалить deprecated `applyEffects()` из FlowEngine

---

## ✅ Milestone 3 — Conversation Logging

> *Транскрипт переписки: фундамент для Inbox, live-chat, human takeover.*

**Когда:** закрыт (коммит `d6de84c` + доработки медиа). Домен `app/Domains/Conversation` в коде.

Спека: [[specs/messaging/conversation-logging]]

### Домен Conversation
- [x] Миграции: `conversations`, `conversation_messages` (monthly partitions на pgsql, sqlite-fallback для тестов)
- [x] `ConversationLoggerInterface` — write port  
- [x] `ConversationStoreInterface` — driver port  
- [x] `MessageLogEntry` DTO + `ConversationRef` + enums  
- [x] `PostgresConversationStore` + `ConversationPartitionManager` (провайдер выбирается по config)

### Capture pipeline
- [x] `PersistConversationMessageJob` + `UpdateConversationDeliveryStatusJob` (очередь `messaging.logging`)  
- [x] Capture inbound: `IncomingMessageJob` до router, идемпотентно по `update_id`  
- [x] Capture outbound: единый funnel `MessageSender::send()` + отдельный путь `FlowMessageSender` (upload-as-send)  
- [x] Capture notify/broadcast: `origin` проставляется в `SendContactNotificationJob` и Broadcasting-джобах  
- [x] Inbound media: `FetchConversationMediaJob` + `MediaSource::Conversation`

### Quality
- [x] GDPR cascade: contacts/assistants/channels → conversations `cascadeOnDelete`
- [x] Тесты: store (идемпотентность/агрегат/status), logger, capture factory

### Осознанно отложено
- [ ] `ConversationReaderInterface` — read port (фаза 8, при переходе на ClickHouse). Сейчас Filament читает Postgres
  через Eloquent напрямую — задокументированный trade-off §10 спеки, не забытый долг.
- [ ] Delivery-status / read-receipts: плумбинг есть и покрыт тестами, но «спит» — Telegram статусов не даёт.

---

## 🔄 Milestone 4 — Broadcasting & Segments

> *Управляемые рассылки + динамические сегменты контактов.*

**Когда:** ядро закрыто (`68d6b5d`, `d3f1c44`), остались две продуктовые доработки

### Broadcasting — готово ✅
- [x] Домен `Broadcasting`: `broadcasts` / `broadcast_recipients` + статусы (draft → running → completed/failed/cancelled)
- [x] `RunBroadcastJob` (fan-out) + `SendBroadcastRecipientJob` + `BroadcastDispatcher` + `BroadcastRecipientResolver`
- [x] Backpressure по длине очереди `messaging.broadcast` + rate-shaping через `broadcast_chunk_size`
- [x] Rate limiting per `(channel, chat)` — переиспользован превентивный лимит `MessageSender`
- [x] Filament: дашборд рассылок (composer, lifecycle-таблица, Send/Cancel) + таб настроек в TenantSettings

### Contact Segments — готово ✅
- [x] `contact_segments` (rules JSON, `cached_count`) + модель
- [x] `ContactSegmentResolver` (tag has/not_has, language/platform, attributes по dot-path; all/any)
- [x] Filament: `ContactSegmentResource` с rules-builder и «Recount»
- [x] `BroadcastTarget::Segment` + `broadcasts.target_segment_id`

### Осталось — 1. Мультиязычный контент рассылки

Объём меньше, чем кажется: **доставка уже готова**. `SendBroadcastRecipientJob:87` вызывает
`$translator->resolveField($broadcast->message, $language)`, язык резолвится на строках 81-85
(`contact.language` → `assistant.default_language` → `TenantSettings::fallback_language`). Сейчас `message`
приходит строкой, и `resolveField(string)` возвращает её как есть. Менять надо только хранение и форму.

- [ ] Миграция `broadcasts.message`: `text` → `jsonb`. Готовый прецедент — `2026_05_08_000002_localize_assistant_messages.php`
  (pgsql `USING jsonb_build_object('en', col)`, sqlite drop+recreate, обратимый `down()` через `->>'en'`)
- [ ] `Broadcast::casts()` — `'message' => 'array'` (по образцу `Assistant::$casts` для `fallback_message`/`busy_message`)
- [ ] Форма: `Textarea::make('message')` в `BroadcastFormSchema:37-42` → `LocalizedTextarea::tabs()`
  (готовый компонент `app/Filament/Support/LocalizedTextarea.php`, уже используется в `AssistantSettings`)
- [ ] Нормализация перед сохранением по образцу `AssistantSettings::cleanLocalized()` (строка → строка/null,
  массив → отфильтрованная карта, пустая → null) в `CreateBroadcast`/`EditBroadcast`
- [ ] Валидация: непустой текст хотя бы на `content_base_language`, иначе все получатели уйдут в `skipped`
  (`SendBroadcastRecipientJob:89-93`)
- [ ] lang-ключи для вкладок локалей — в `broadcast.php` их сейчас нет
- [ ] Опционально: вынести дублирующийся приватный `resolveLanguage()` из `SendBroadcastRecipientJob:186-195`
  и `SendContactNotificationJob` в общий сервис — публичного резолвера языка контакта вне flow нет

### Осталось — 2. Группы контактов

**Чистое поле:** `contact_groups` / `contact_group_members` отсутствуют в коде полностью — ни миграций, ни моделей,
ни DTO. Единственный след — комментарий в `SegmentConditionType.php:10` «Group conditions arrive with the
contact-groups feature». В документации таблицы описаны в `architecture/platform/05-contacts.md`.

- [ ] Миграции `contact_groups` + `contact_group_members` (pivot)
- [ ] Модель `ContactGroup` + связь на `Contact`
- [ ] `SegmentConditionType::Group` + ветка в `ContactSegmentResolver::applyCondition()` (строки 87-100 — точка
  расширения: новый case в enum + новый приватный `applyGroup()`) + операторы `in`/`not_in`
- [ ] Filament: опция `group` в Repeater условий (`ContactSegmentFormSchema:47-57`) + `operatorOptions()` (83-100)
- [ ] Filament: назначение групп в `ContactResource`
- [ ] **Сверить со спекой** `reference/specs/flow-engine/05-group-storage.md` — там описан Redis-кэш
  `tenant:{id}:contact_groups`, наполняемый статической экстракцией из `assign.target`. Это другая механика,
  чем таблица групп; расхождение надо разрешить до реализации, иначе получатся два источника правды.

> **Оценка приоритета:** группы — единственная позиция M4, которую можно вынести за релиз. Сегменты уже покрывают
> tag / language / platform / attributes, а группы требуют и таблиц, и UI, и разрешения конфликта со спекой.

---

## ✅ Milestone 6 — Call Transport & Event Chains

> *`emit_event` нода + полная цепочка flow-to-flow через события.*

**Когда:** закрыт (коммит `1d45430` и ранее)

Спека: [[specs/flow-engine/nodes/07-emit-event]]

- [x] `EmitEventNodeHandler` (type `emit_event`)  
- [x] `TenantEventRepository` + `ResolveEventTriggersService`  
- [x] `event` триггер: `DispatchFlowTriggerEventJob` фанит `StartFlowFromEventJob` на каждый подписанный триггер;
  payload доступен как `flow.event.*`; формат имени события выровнен между trigger и emit  
- [x] Тесты цепочки: `DispatchFlowTriggerEventJobTest`, `StartFlowFromEventJobTest`  

> `emit_event` закрывает superseded `go_to_flow` сценарий «jump без возврата».

---

## 🔄 Milestone 8 — Inbox / Live Chat

> *Операторский интерфейс: просмотр диалогов, ответы, передача оператору.*

**Когда:** read-only часть уже в коде (фаза 7 M3); операторская — предстоит

**Что готово:**
- [x] Filament: `ConversationResource` + inbox-лента, scope по `assistant_id`  
- [x] Filament: `ViewConversation` — хронологический транскрипт (пузыри, keyboard, delivery-status), read-only

**Фундамент под takeover уже заложен в M3** — при планировании этим надо пользоваться, а не строить заново:
`MessageSenderType::Staff` и `MessageOrigin::Staff` уже существуют как case'ы; в таблицах зарезервированы
`conversations.owner_type`, `conversations.owner_staff_user_id`, `conversation_messages.sender_staff_user_id`
(колонки есть, в коде не используются нигде); `unread_count` уже инкрементируется на inbound.
Целевой сценарий описан в спеке `messaging/conversation-logging.md` §7.6.

### 🔒 Блокер безопасности — закрыть до выкладки
- [ ] **Inbox сейчас доступен любому аутентифицированному пользователю.** `ConversationResource::shouldRegisterNavigation()`
  проверяет только `Auth::user() instanceof User`; `ConversationPolicy` не существует; в `Permission` enum вообще
  нет прав на диалоги. Транскрипты переписки — самые чувствительные данные в системе.
- [ ] Добавить `ViewConversations` / `ReplyConversations` в `Permission` enum. Внимание: `Permission::group()`
  (строки 128-158) — `match` **без `default`**, новый case обязателен там; плюс `label()`/`description()`
  требуют ключей в `staff.permissions.*`, а `RoleEnum::permissions()` — раздачи ролям
- [ ] `ConversationPolicy` + регистрация (общего `AuthServiceProvider` нет — полиси регистрируются точечно
  в сервис-провайдерах)

### Takeover и маршрутизация
- [ ] Механизм передачи: использовать `owner_type` (`bot` / `staff`) + `owner_staff_user_id`, а не новый
  case в `ConversationStatus` (там `open`/`closed`/`snoozed` — это состояние треда, а не владелец)
- [ ] **Точка врезки в роутер — шаг 4** (`MessageRouter:130-145`). Сегодня решение «выполнять / дропнуть»
  принимает `SessionStateRouter::decide()` на основании одной лишь `FlowSession` и ничего не знает про
  `Conversation`. Нужен lookup треда до вызова оркестратора. Входящие при этом уже логируются **до** роутинга
  (`IncomingMessageJob:96-113`), так что при takeover переписка не потеряется.
- [ ] Возврат управления flow: оператор закрывает тред → `owner_type = bot`, resume или новый старт
- [ ] Действия в UI: «Взять диалог» / «Вернуть боту», уважающие `user_assistants`

### Ответ оператора
- [ ] Reply input в `ViewConversation` → отправка через тот же `MessageSender::send()`
- [ ] **`ConversationCaptureFactory::forOutbound()` строка 95 хардкодит `MessageSenderType::Assistant`** —
  записать исходящее как `Staff` сейчас невозможно; нужен проброс `senderType` и `senderStaffUserId`
  (последний параметр `MessageLogEntry` его уже принимает)
- [ ] Резолв активного `ChannelContact` для отправки вне flow — готовый образец в `FallbackMessageService:31-39`
- [ ] Учесть, что `metadata` без `contact_id`/`assistant_id` тихо выпадает из транскрипта
  (`ConversationCaptureFactory:77-82`)

### Unread и назначение
- [ ] **Сброс непрочитанных не реализован.** `unread_count` инкрементируется в `PostgresConversationStore::bumpAggregate()`
  (141-144), но метода `markRead` нет ни в `ConversationStoreInterface` (там всего 4 метода), ни где-либо ещё
- [ ] Badge непрочитанных в навигации
- [ ] **Назначение оператора упирается в scope:** `User::getTenants()` (159-173) возвращает *все* ассистенты
  платформенного тенанта, отфильтрованные через `Gate`, и пивот `user_assistants` при этом **не используется**,
  хотя связь `User::assistants()` объявлена и панель настроена на `ownershipRelationship: 'assistants'`.
  Это надо решить до staff assignment, иначе «мой диалог» не будет означать ничего.

### Качество треда
- [ ] **Пагинация.** `ViewConversation::messages()` (56-62) грузит весь тред одним запросом без лимита
- [ ] **Медиа не отдаётся.** Blade рендерит только текстовую плашку с именем файла; `media_file_id` в дескрипторе
  есть, механизм `MediaService::signedUrl()` + маршрут `media.files.raw` (TTL 300 s) готов, но к транскрипту
  не подключён
- [ ] `FallbackMessageService` не пишет в транскрипт (нет `contact_id`/`assistant_id` в metadata) — busy/fallback
  сообщения невидимы оператору

### Real-time
- [ ] **Инфраструктуры нет:** `config/broadcasting.php` отсутствует, `BROADCAST_CONNECTION=log`, в зависимостях нет
  ни Reverb, ни Pusher, ни laravel-echo. Рантайм — RoadRunner.
- [ ] Прагматичный вариант для передачи в тестирование — Filament polling, прецеденты уже есть
  (`FlowLogsTable` `poll('60s')`, `FlowSessionsTable` `poll('30s')`). WebSocket — отдельное решение со стеком
  и зависимостями, за пределами релизного трека.

---

## 📋 Milestone 7 — Solutions Framework

> *Механизм расширения платформы внешними пакетами (Solutions).*

**Когда:** после M8. Перенесён вниз осознанно: до первого реального Solution под разработку каркас расширения
не окупается, а релиз в тестирование он не блокирует.

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

## 📋 Milestone 12 — MCP Server

> *FAPost как surface для AI-агентов: внешний агент (Claude, ChatGPT, собственный агент арендатора)
> читает и управляет платформой через Model Context Protocol.*

**Когда:** после M2; растёт вместе с M3/M4 (транскрипты и рассылки — основной материал для tool'ов)

**Почему важно:** у платформы уже есть всё, что интересно агенту — ассистенты, flow-определения, контакты и
сегменты, транскрипты диалогов, рассылки. Сейчас это доступно только через Filament руками. MCP-сервер превращает
Core в программируемую поверхность: «покажи, почему сессия застряла», «собери сегмент по описанию», «подготовь
черновик рассылки» — без отдельного REST API и без ручной работы в админке.

### Архитектурные решения (нужен ADR перед кодом)

1. **Границы кода.** Новый домен `app/Domains/Mcp` (`Server`, `Tools/{Domain}`, `Resources`, `Prompts`, `Registry`,
   `Auth`, `Audit`). Base folder `app/Mcp`, который по умолчанию генерирует `laravel/mcp`, не заводим — это отдельное
   решение (см. правило Directory Boundaries в `CLAUDE.md`).
2. **Transport.** Streamable HTTP: `Mcp::web('/mcp', …)` в отдельном route-файле. Local/stdio-сервер — только для
   dev-инструментов, не для арендаторов.
3. **Tenant scoping.** Токен резолвит tenant → `TenantSwitcher::runForTenant()` с restore в `finally`. Без tenant
   context — fail fast, никакого «default tenant». Ни один tool не ходит в landlord напрямую (только контракты
   `Tenancy/Contracts`).
4. **Авторизация.** Переиспользуем `Permission` enum и существующие Policy-классы: токен несёт набор permissions,
   tool спрашивает `Gate`, а не изобретает свою проверку. `Permission::isSensitive()` → отдельный scope на токене.
5. **Расширяемость.** `McpToolRegistry` по аналогии с `ActionHandlerRegistry` / `RagAdapterRegistry`; контракт
   `McpToolInterface` живёт в `packages/fapost-foundation`. Core не знает про `hr.*` — Solutions регистрируют свои
   tool'ы сами (связь с M7).
6. **Octane.** MCP-ingress stateless и допустим в Octane scope наравне с webhook: tenant context и текущий сервер —
   только в `scoped` bindings, никаких static между запросами.
7. **Безопасность операций.** v1 — read-first. Разрушающие операции (delete, publish, send) за отдельным scope, с
   `destructiveHint` / `readOnlyHint` в аннотациях tool'а и с idempotency-маркером на write-пути.
8. **Аудит и лимиты.** Каждый вызов пишется в `mcp_audit_log` (tenant, token, tool, hash аргументов, исход,
   длительность); rate limit per token.

### Фаза 1 — Фундамент

- [ ] ADR: MCP surface (transport, домен, auth-модель, scopes, аудит)
- [ ] Решение по зависимости: `laravel/mcp` vs собственная JSON-RPC реализация (требует согласования)
- [ ] Домен `Domains/Mcp` + service provider + route-группа `/mcp`
- [ ] Token model: миграция `mcp_tokens` (hash, scopes, last_used_at) + issue/revoke; в проекте сейчас нет Sanctum —
      выбор между Sanctum и собственными токенами делается в ADR
- [ ] Middleware `mcp.auth` + `mcp.tenant` (fail fast без tenant context)
- [ ] `McpToolInterface` в foundation + `McpToolRegistry` в Core
- [ ] `mcp_audit_log` + rate limiting per token
- [ ] Тестовый харнес для tool'ов + phpat-правило: tool не обращается к landlord и не тянет Filament

### Фаза 2 — Read-инструменты (v1)

- [ ] Assistants: `assistants.list`, `assistants.get`
- [ ] Flows: `flows.list`, `flows.get` (definition JSON), `flows.validate` (через `ValidateFlowService`)
- [ ] Contacts: `contacts.search`, `contacts.get`, `segments.list`, `segments.preview` (через `ContactSegmentResolver`)
- [ ] Conversations: `conversations.search`, `conversations.transcript`
- [ ] Broadcasting: `broadcasts.list`, `broadcasts.stats`
- [ ] Runtime-диагностика: `flow_sessions.inspect`, `flow_logs.tail`

### Фаза 3 — Write-инструменты (gated)

- [ ] `contacts.set_tag`, `contacts.update_attributes`
- [ ] `segments.create` / `segments.update` + recount
- [ ] `broadcasts.create_draft`; отправка — только со scope `broadcast:send`
- [ ] `flows.publish` через `PublishFlowService` (scope `flow:publish`, публикация только валидного графа)
- [ ] `messages.send` в существующий диалог — `origin=mcp`, обязательно логируется в транскрипт
- [ ] Идемпотентность и audit-запись на каждом write-пути

### Фаза 4 — Resources и Prompts

- [ ] Resources: `flow://{id}/definition`, `conversation://{id}/transcript`, `schema://variables`, `docs://node/{type}`
- [ ] Resource templates + пагинация больших выборок
- [ ] Prompts: разбор застрявшей сессии, сборка сегмента по описанию, черновик flow по описанию
- [ ] Медиа: решение blob vs signed URL

### Фаза 5 — Расширяемость и UI

- [ ] Регистрация tool'ов из Solutions/Plugins через `McpToolRegistry` (E2E: Solution ставится → tool доступен)
- [ ] Filament: MCP Tokens resource (issue/revoke/scopes/last used) + просмотр audit log
- [ ] Онбординг: генерация client-конфига (URL + заголовки) для подключения агента
- [ ] `developers/` HTML-страница: как написать MCP tool в Solution

### Открытые вопросы

- Один сервер на платформу с tenant-скоупом по токену или отдельный URL на арендатора
- Нужен ли OAuth 2.1 до маркетплейса плагинов, или bearer-токенов достаточно
- Нужны ли MCP Apps (интерактивные HTML-панели в клиенте) — риск дублирования Filament

> **Вне скоупа этого блока:** MCP *client* — вызов внешних MCP-серверов изнутри flow (нода `mcp_call` или новый
> транспорт для `call`). Это отдельное направление, завязанное на AI-слой и M5.

---

# Бэклог

> Направления, снятые с ближайшей очереди. Номера milestone'ов сохранены, чтобы не ломать ссылки.

## 🧊 Milestone 5 — RAG Feature

> *Интеграция с базами знаний: поиск по документам внутри flow.*

**Когда:** после M12 (MCP Server). Раньше стоял сразу после M2 — перенесён в бэклог осознанно.

**Почему перенесён:** runtime-часть уже в коде (`RagQueryNodeHandler`, `RagAdapterRegistry`, валидация), но нода
нерабочая до тех пор, пока нет storage, адаптера и UI. Основной блокер не технический, а решенческий — не выбран
провайдер (собственный pgvector vs внешний сервис), и цена ошибки здесь выше, чем польза от быстрого запуска.
До тех пор `rag_query` остаётся в палитре и падает на runtime guard'е — это осознанный, а не забытый долг.

Спека: [[specs/flow-engine/nodes/09-rag-query]]

**Что уже есть:**
- [x] `RagAdapterRegistry` (plug-in адаптеры провайдеров)
- [x] `RagQueryNodeHandler` (type `rag_query`) — пишет `rag.*` в state
- [x] RAG validation в `ValidateFlowService` (config shape / runtime guard провайдера)

**Что предстоит:**
- [ ] Решение по провайдеру эмбеддингов и хранилищу векторов
- [ ] `knowledge_bases` таблица + миграция + Filament resource
- [ ] `StructuredRagResult` DTO (found, confidence, answer, intent, metadata)
- [ ] Первый адаптер + проверка `knowledge_base` в `ValidateFlowService` (сейчас ждёт storage)

---

## Зависимости между milestone'ами

```
Релизный трек «до тестирования»:

    M2 🔄 ──► M4 🔄 ──► M8 🔄 ──► ▶ выкладка на сервер, передача в тестирование

Далее:

    ▶ ──► M12 (MCP Server) ──► M7 (Solutions) ──► M11 (Plugins)
                                    │
                                    └──► M10 (SaaS) ─ отдельный репо

    M5 (RAG) — 🧊 бэклог, после M12
```

Закрытые основания: M1 ✅, M3 ✅ (транскрипты — фундамент для M8), M6 ✅.

---

## Критерии «Production Ready» (после M2–M3)

- [x] Полный 6-шаговый routing pipeline end-to-end (D-1 + D-2)  
- [x] Все диалоги логируются (inbound + outbound + notify + broadcast + медиа)  
- [-] Concurrency тесты: distributed lock и optimistic retry покрыты; **heartbeat не подключён**, real-Redis
  интеграции нет  
- [x] phpat CI правила enforced через `composer run test:arch`  
- [x] GDPR cascade delete (contacts/assistants/channels → conversations)  
- [ ] Load test: 100 concurrent sessions без state leakage (Octane scope)

---

## Связано с

- [[TASKS]] — детальные задачи реализации
- [[INDEX]] — навигация по документам
- [[PROJECT]] — описание проекта
- [[plans/flow-engine/implementation-plan]] — план реализации flow engine
