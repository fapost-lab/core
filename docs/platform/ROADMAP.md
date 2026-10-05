# FaPost Core — Дорожная карта

История инженерных майлстоунов: что было сделано и чем закрыто. Навигация по документам — [INDEX](../INDEX.md).

> **Этот файл — запись прошлого, а не план.** Открытые направления живут в спеках Jig под
> `.ai/specs/` (`.ai/scripts/jig spec list`); каждый незакрытый майлстоун ниже сведён к абзацу со
> ссылкой на свою спеку. Продуктовая декомпозиция идеи — [`../roadmap.md`](../roadmap.md).

> **Статусы:** ✅ завершено · 🔄 в работе / частично · ⏳ следующее · 📋 запланировано · 🧊 бэклог · 🔮 будущее

---

## 🎯 Релизный трек: готов к выкладке

Цель трека — довести **M2 → M4 → M8** и передать в тестирование. Все три закрыты.

| Milestone | Статус | Итог |
|---|---|---|
| M2 Runtime Hardening | ✅ | Один session lock вместо двух вложенных, heartbeat продлевает TTL, параметры в конфиге |
| M4 Broadcasting & Segments | ✅ | Мультиязычный контент рассылки, группы контактов, закрыт дефект таргетинга |
| M8 Inbox / Live Chat | ✅ | Права и политика, takeover, ответ оператора, unread, пагинация, медиа, polling |

Полный сьют — **936 зелёных**, 1 skipped (sqlite-ветка миграции); `composer run test:arch` — без ошибок.

**Вне трека:** Redis-integration suite (см. § Milestone 2) и load test из критериев Production Ready
закрыты — все критерии Production Ready выполнены.

Что дальше — больше не решается здесь: порядок работ после выкладки задаётся шагами в
[`../roadmap.md`](../roadmap.md) и спеками под `.ai/specs/`. M9 (Multi-channel / WhatsApp) удалён из планов.

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

## ✅ Milestone 2 — Runtime Hardening

> *Продакшн-готовность: полный pipeline обработки, конкурентность, CI-правила, loop завершение.*

**Когда:** закрыт, включая Redis-integration suite.

### Loop node — закрыт ✅
- [x] 8.1 Reachability — `ValidateFlowService::validateLoopReachesLoopEnd`, publish-error `loop_missing_loop_end`  
- [x] 7.2 Properties-конфликты — array `item_type` drift блокирует publish (`variable_properties_conflict`)  
- [x] `.length` резолвер для array-переменных в условиях branch (`OperandResolver`)  
- [x] Budget: `flow.execution.max_iterations` + publish-error на literal count > 100  
Остаток вынесен в V1.x warning-only (8.5, 8.7, UI для `max_size`) — см. [TASKS](TASKS.md).  
Спека: [specs/flow-engine/nodes/11-loop](../reference/specs/flow-engine/nodes/11-loop.md)

### Routing pipeline (Phase D)
- [x] **D-1** `MessageRouter` 6-шаговый pipeline + `DropPolicy` + `SessionStateRouter`  
- [x] **D-2** `TypingIndicatorService` (индикатор печатания во время выполнения)  
- [x] **D-3** `/reset` migration под `BuiltinCommandsRegistry` с force unlock  
Спека: [architecture/adr/09-message-routing-concurrency](architecture/adr/09-message-routing-concurrency.md)

### CI enforcement
- [x] phpat правила: Migration Isolation, Handler Version Contract, Dependency Direction (`composer run test:arch`)  
- [x] Concurrency hardening: optimistic-lock retry и distributed lock покрыты (`FlowOrchestratorRetryTest`,
  `FlowExecutionGuardTest`, `SessionLockManagerTest`, `LockAcquisitionPolicyTest`), включая integration-suite
  на живом Redis

### Locking — закрыт ✅

Было: две параллельные реализации блокировки. Пакет `Domains/Flow/Concurrency/` умел продлевать TTL, но не
использовался в production-коде вообще; реальные локи держали `MessageRouter` (`Cache::lock`, ключ с
`platform:externalUserId`) и `FlowExecutionGuard` (`LockProvider`, ключ с `contactId`) — два вложенных лока
с разными ключами, ни один не продлевался.

Стало — реализован ADR-09:

- [x] **Один лок на прогон.** `LockScope(tenant, contact, assistant)` — единственный ключ. `MessageRouter`
  захватывает его через `LockAcquisitionPolicy` и публикует handle в новый `SessionLockRegistry` (scoped).
- [x] **`FlowExecutionGuard` переписан** с фреймворкового `Lock` на `SessionLockManager` и стал ре-энтрантным:
  видит в реестре собственный захват и проходит насквозь. Вложенный guard на другой scope возвращает слот
  внешнему владельцу, иначе heartbeat внешнего лока молча остановился бы.
- [x] **Heartbeat подключён** в `FlowEngine::executeLoop()` рядом с тиком typing-индикатора. При потере владения —
  новый `SessionLockLostException`, роутер отдаёт `dropped('lock_lost')`.
- [x] **Параметры в `config/flow.php`** (`lock.ttl_seconds`, `acquisition_retries`, `retry_delay_ms`,
  `heartbeat.*`); пять хардкодов `30` убраны, `LockHeartbeat` / `LockAcquisitionPolicy` собираются из конфига.
- [x] Удалены мёртвые `MessageRouter::tickHeartbeat()` и `buildLockKey()`.
- [x] Тесты: `LockHeartbeatTest`, `SessionLockRegistryTest`, ре-энтрантность в `FlowExecutionGuardTest`,
  `FlowEngineSessionLockTest` (обрыв при потере лока), `lock_lost` в `MessageRouterTest`. Полный сьют — 874 зелёных,
  `composer run test:arch` — без ошибок.

> **Почему тик привязан к итерации цикла, а не к таймеру.** TTL должен покрывать промежуток между двумя тиками —
> то есть самую медленную одну ноду, а не весь прогон. `HttpTransport` таймаутит на 10 с при TTL 30 с, запас есть.
> Отдельный планировщик тиков не вводился. Horizon `timeout: 60` на очереди `flow.execution` при этом не конфликтует:
> лок продлевается изнутри исполнения.

### Интеграционные тесты на живом Redis — закрыт ✅
- [x] `tests/Feature/Redis` (группа `redis`) в общем прогоне: `SessionLockManager`, `LockHeartbeat`,
  `LockAcquisitionPolicy`, `FlowExecutionGuard` и потеря блокировки в `FlowEngine` — на настоящем Redis,
  с префиксом ключей и Lua-скриптами. `composer run test:redis` — только эта группа.
- [x] Пути в обход роутера берут блокировку сами: `ResumeTimedOutSendMessageNodeJob` и
  `StartFlowFromEventJob` работают под `FlowExecutionGuard` и перечитывают сессию под ней
  (до этого оба входили в движок без блокировки).

### Cleanup (Phase E)
- [x] Удалить `effects[]` из `NodeExecutionResult`
- [x] Удалить deprecated `applyEffects()` из FlowEngine

---

## ✅ Milestone 3 — Conversation Logging

> *Транскрипт переписки: фундамент для Inbox, live-chat, human takeover.*

**Когда:** закрыт (коммит `d6de84c` + доработки медиа). Домен `app/Domains/Conversation` в коде.

Спека: [specs/messaging/conversation-logging](../reference/specs/messaging/conversation-logging.md)

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

## ✅ Milestone 4 — Broadcasting & Segments

> *Управляемые рассылки + динамические сегменты контактов.*

**Когда:** закрыт

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

### Мультиязычный контент рассылки — закрыт ✅

Доставка менять не пришлось: `SendBroadcastRecipientJob` уже вызывал `resolveField()`, строка проходила насквозь
только из-за типа колонки.

- [x] Миграция `broadcasts.message`: `text` → `jsonb` (прецедент `2026_05_08_000002_localize_assistant_messages.php`),
  `NOT NULL` снят — «есть контент» теперь правило уровня приложения, а не одной скалярной колонки
- [x] `Broadcast::casts()` — `'message' => 'array'`; PHPDoc исправлен (в нём не хватало и `target_segment_id`)
- [x] Форма: вкладки локалей через существующий `LocalizedTextarea::tabs()`
- [x] Нормализация карты локалей при сохранении + валидация: рассылку без текста на `content_base_language`
  теперь нельзя создать. Раньше такая рассылка молча отправляла всех получателей в `skipped`
- [x] lang-ключи en/ru/uk
- [x] Тесты: обратимость миграции (pgsql), каст модели, доставка нужной локали, фолбэк для языка вне карты

### Группы контактов — закрыт ✅

- [x] Миграции `contact_groups` + `contact_group_members`, модель `ContactGroup`, связь `Contact::groups()`
- [x] `SegmentConditionType::Group` + `ContactSegmentResolver::applyGroup()` с операторами `in` / `not_in`
- [x] Filament: ресурс групп, опция `group` в конструкторе правил сегмента, назначение групп контакту
- [x] lang en/ru/uk
- [x] Тесты: резолвер (`in`, `not_in`, несколько групп, комбинаторы `all`/`any`), связь и pivot,
  сквозной путь сегмент → группа → рассылка

### 🔒 Побочно закрыт дефект таргетинга

Резолвер сегментов **отказывал в опасную сторону**: условие, которое он не мог разобрать — пустой список групп,
пустой тег, битый dot-path атрибута, неизвестный тип — молча выбрасывалось. Для `match: all` это означало, что
сегмент вместо «никто» становился «все контакты тенанта», то есть рассылка уходила по всей базе.

Теперь такое условие резолвится в «никто» (`matchNothing()`), под `any` остаётся корректным no-op, а сегмент
вообще без условий по-прежнему осознанно значит «все». Покрыто data-provider тестом по всем типам условий.

> **Расхождения со спекой `05-group-storage` нет** (проверено). Та спека описывает *группы переменных* — вложенные
> объекты внутри `contact.attributes` JSONB (`form.input1`, `address.city`), которые пишут ноды `assign`/`input`,
> и Redis-кэш их имён для автокомплита в билдере. Это другая сущность, чем группы контактов как список аудитории.
> Совпадает только слово «группа» и, к сожалению, имя Redis-ключа `tenant:{id}:contact_groups`.

---

## ✅ Milestone 6 — Call Transport & Event Chains

> *`emit_event` нода + полная цепочка flow-to-flow через события.*

**Когда:** закрыт (коммит `1d45430` и ранее)

Спека: [specs/flow-engine/nodes/07-emit-event](../reference/specs/flow-engine/nodes/07-emit-event.md)

- [x] `EmitEventNodeHandler` (type `emit_event`)  
- [x] `TenantEventRepository` + `ResolveEventTriggersService`  
- [x] `event` триггер: `DispatchFlowTriggerEventJob` фанит `StartFlowFromEventJob` на каждый подписанный триггер;
  payload доступен как `flow.event.*`; формат имени события выровнен между trigger и emit  
- [x] Тесты цепочки: `DispatchFlowTriggerEventJobTest`, `StartFlowFromEventJobTest`  

> `emit_event` закрывает superseded `go_to_flow` сценарий «jump без возврата».

---

## ✅ Milestone 8 — Inbox / Live Chat

> *Операторский интерфейс: просмотр диалогов, ответы, передача оператору.*

**Когда:** закрыт

**Что готово:**
- [x] Filament: `ConversationResource` + inbox-лента, scope по `assistant_id`  
- [x] Filament: `ViewConversation` — хронологический транскрипт (пузыри, keyboard, delivery-status), read-only

**Фундамент под takeover уже заложен в M3** — при планировании этим надо пользоваться, а не строить заново:
`MessageSenderType::Staff` и `MessageOrigin::Staff` уже существуют как case'ы; в таблицах зарезервированы
`conversations.owner_type`, `conversations.owner_staff_user_id`, `conversation_messages.sender_staff_user_id`
(колонки есть, в коде не используются нигде); `unread_count` уже инкрементируется на inbound.
Целевой сценарий описан в спеке `messaging/conversation-logging.md` §7.6.

### Безопасность — закрыт ✅

Инбокс был доступен любому аутентифицированному пользователю: `shouldRegisterNavigation()` проверял только
`instanceof User`, политики не существовало, прав на диалоги в `Permission` не было вовсе.

- [x] `Permission::ViewConversations` / `ReplyConversations`, группа `conversations`, оба помечены sensitive
- [x] `ConversationPolicy` + регистрация в провайдере домена; `ConversationResource` спрашивает политику
- [x] Кастомная страница треда авторизуется явно (`canAccess()` + `Gate::authorize('view', …)` в `mount()`) —
  Filament автоматически покрывает только встроенные страницы ресурса
- [x] Раздача ролям: `ContentManager` получает чтение и ответ (роль и так рассылает сообщения всем контактам,
  ответ в треде не расширяет её радиус); `Analyst` — ничего, это роль агрегатных цифр
- [x] 9 тестов авторизации, включая регрессию «чужой тред → 404 даже с правом»

### Takeover — закрыт ✅

- [x] Владелец через зарезервированный `owner_type` + `owner_staff_user_id`, новый enum `ConversationOwner`
  (`bot` / `staff`). Отдельно от `ConversationStatus`: статус — жизненный цикл треда, владелец — кто отвечает;
  перехваченный оператором диалог по-прежнему открыт
- [x] Контракт `ConversationOwnershipInterface` + Eloquent-реализация. Держится отдельно от
  `ConversationStoreInterface`: тот порт — append-only бэкенд сообщений, который однажды уедет в колоночное
  хранилище, а владелец треда остаётся в оперативной БД
- [x] Врезка в `MessageRouter` шагом 4a — **до** классификации по состоянию сессии. Порядок принципиален: сессия
  могла остаться в `waiting_input` с момента до передачи, и классификация первой возобновила бы flow, который
  оператор только что заменил собой. Входящее не теряется — `IncomingMessageJob` пишет его в транскрипт до роутинга
- [x] Глобальные команды (`/reset`) по-прежнему обрабатываются раньше проверки владельца
- [x] Действия «Взять диалог» / «Вернуть боту» в UI, возврат очищает владельца

### Ответ оператора — закрыт ✅

- [x] `ConversationReplyService` поверх того же `MessageSender::send()`, отправка на канал самого треда
- [x] Атрибуция в транскрипте: `ConversationCaptureFactory` выводит `senderType = Staff` из `origin`, а не из
  отдельного ключа metadata — так отправитель не может присвоить авторство оператора, не объявив staff-origin
- [x] Ответ оператора уходит **без** `parse_mode`, в отличие от прочих исходящих путей. Те несут контент, который
  автор писал как разметку; этот — прозу, набранную в поле чата. Под `parse_mode=HTML` обычное «R&D» или «5 < 10» —
  это битая разметка, и Telegram отклоняет сообщение целиком

### Тред и лента — закрыт ✅

- [x] Сброс непрочитанных при открытии треда (после проверки прав, чтобы неавторизованный запрос не имел эффекта)
- [x] Badge непрочитанных в навигации, скоуп переиспользует `getEloquentQuery()`
- [x] Пагинация треда (50 сообщений + догрузка) — раньше грузился весь тред одним запросом
- [x] Медиа в транскрипте через `MediaService::signedUrl()`; `pending` / `failed` показываются состоянием
- [x] Автообновление polling'ом (30 s). Real-time стека в проекте нет — `config/broadcasting.php` отсутствует,
  ни Reverb, ни Pusher в зависимостях; WebSocket остаётся отдельным решением вне релизного трека
- [x] `FallbackMessageService` теперь пишется в транскрипт: раньше в metadata не было `contact_id` / `assistant_id`,
  и busy/fallback-ответы молча выпадали из лога — оператор видел контакта, говорящего в пустоту

## 📋 Milestone 9 — Forms / Data Collection

> *Сбор структурированных данных через веб-форму (Telegram Mini App или hosted-страница) вместо цепочки
> `input`-нод в чате.*

Направление перенесено в спеку **`.ai/specs/forms-data-collection/`**: там идея, границы Core/Solution,
восемь архитектурных решений, четыре фазы и открытые вопросы. Здесь остаётся только запись, что
направление существует; чекбоксов и статусов по нему тут больше нет.

Продуктовый роадмап держит эту фичу вне приоритета — обоснование в [`../roadmap.md`](../roadmap.md)
§ Out of scope.

---

## 📋 Milestone 7 — Solutions Framework

> *Механизм расширения платформы внешними пакетами (Solutions).*

Разнесён на две спеки по продуктовым шагам 4 и 6:

- **`.ai/specs/solution-activation-lifecycle/`** — манифест и его валидация, хранилище активаций и
  реестр, Core-реализация `CoreRegistrarInterface`, экран активации, lifecycle-тесты
  install → activate → handler available.
- **`.ai/specs/first-solution/`** — первый Solution (FaPost HR, `hr.sync_employee`,
  `hr.create_assessment`), собранный строго через публичные контракты, в собственном репозитории.

Документ, на котором они стоят: [architecture/adr/05-foundation-contract-package](architecture/adr/05-foundation-contract-package.md).

---

## 🔮 Milestone 10 — SaaS Shell

> *Многоарендный SaaS: биллинг, план-управление, onboarding.*

**Когда:** отдельный репо `fapost-saas`, после стабилизации Core

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

Перенесён в спеку **`.ai/specs/ecosystem-distribution/`** (продуктовый шаг 8). Там зафиксировано,
что вопрос сознательно не открывается до тех пор, пока первый Solution не собран через публичные
контракты: именно та сборка показывает, каких контрактов касается внешний автор. Концепты этого
майлстоуна (`PluginRegistry`, runtime install, store UI, sandbox, SDK) перенесены туда как кандидаты,
а не как решения.

Документ: [architecture/adr/06-frontend-extension-boundary](architecture/adr/06-frontend-extension-boundary.md).

---

## 📋 Milestone 13 — Audit Log / Staff Activity

> *Журнал действий персонала: кто, когда и что изменил в панели арендатора.*

Перенесён в спеку **`.ai/specs/audit-log/`**: границы «пишем / не пишем», хранение в tenant-схеме,
ретенция и открытые вопросы по UI и видимости журнала — там.

Продуктовый роадмап держит это вне приоритета — обоснование в [`../roadmap.md`](../roadmap.md)
§ Out of scope.

---

## 📋 Milestone 12 — MCP Server

> *FaPost как surface для AI-агентов: внешний агент читает и управляет платформой через
> Model Context Protocol.*

Перенесён в спеку **`.ai/specs/mcp-server/`**: восемь архитектурных решений (домен, транспорт,
tenant scoping, авторизация через существующие Policy, реестр tool'ов, stateless ingress, read-first,
аудит и лимиты), пять фаз и открытые вопросы — там. Несогласованная зависимость `laravel/mcp`
и выбор модели токенов зафиксированы в спеке как нерешённые.

Вне скоупа и там, и здесь: MCP *client* — вызов внешних MCP-серверов изнутри flow.

Продуктовый роадмап держит это вне приоритета — обоснование в [`../roadmap.md`](../roadmap.md)
§ Out of scope.

---

# Бэклог

> Направления, снятые с ближайшей очереди. Номера milestone'ов сохранены, чтобы не ломать ссылки.

## 🧊 Milestone 5 — RAG Feature

> *Интеграция с базами знаний: поиск по документам внутри flow.*

Перенесён в спеку **`.ai/specs/rag-knowledge-bases/`**. Состояние на входе: runtime-часть в коде
(`RagAdapterRegistry`, `RagQueryNodeHandler`, RAG-валидация в `ValidateFlowService`), нода `rag_query`
остаётся в палитре и падает на runtime guard'е — осознанный документированный долг. Блокер не
технический, а решенческий: не выбран провайдер эмбеддингов и хранилище векторов.

---

## Зависимости между milestone'ами

Закрытые основания: M1 ✅, M2 ✅, M3 ✅ (транскрипты — фундамент для M8), M4 ✅, M6 ✅, M8 ✅.

Порядок незакрытых направлений больше не ведётся здесь: он задаётся порядком шагов в
[`../roadmap.md`](../roadmap.md) и волнами внутри каждой спеки. Прогресс по спеке читается
командой `.ai/scripts/jig spec list`, а не отсюда.

M10 (SaaS Shell) остаётся вне этого репозитория и вне спек — это отдельный закрытый продукт.

---

## Критерии «Production Ready» (после M2–M3)

- [x] Полный 6-шаговый routing pipeline end-to-end (D-1 + D-2)  
- [x] Все диалоги логируются (inbound + outbound + notify + broadcast + медиа)  
- [x] Concurrency тесты: distributed lock, heartbeat и optimistic retry покрыты, в том числе на настоящем Redis  
- [x] phpat CI правила enforced через `composer run test:arch`  
- [x] GDPR cascade delete (contacts/assistants/channels → conversations)  
- [x] Load test: 100 concurrent sessions без state leakage — `make loadtest` (3 тенанта × 100 контактов × 3 сообщения, 4 воркера, 2026-09-22): 0 утечек, 0 межтенантных строк, 0 дропов, 0 failed jobs

---

## Связано с

- `.ai/specs/` — открытые направления как спеки Jig (`.ai/scripts/jig spec list`)
- [TASKS](TASKS.md) — история реализованного по разделам
- [INDEX](../INDEX.md) — навигация по документам
- [plans/flow-engine/implementation-plan](../archive/platform/plans/flow-engine/implementation-plan.md) — план реализации flow engine
