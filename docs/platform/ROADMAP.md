# FaPost Core — Дорожная карта

Product vision от текущего состояния до SaaS-оболочки, Solutions и Inbox.  
Детализация по задачам — [[TASKS]]. Навигация по документам — [[INDEX]].

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

**Осталось до выкладки, вне трека:** Redis-integration suite (вся блокировка покрыта моками — см. § Milestone 2)
и load test из критериев Production Ready.

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

## ✅ Milestone 2 — Runtime Hardening

> *Продакшн-готовность: полный pipeline обработки, конкурентность, CI-правила, loop завершение.*

**Когда:** закрыт. Открытым остаётся только Redis-integration suite (см. ниже) — он не блокирует выкладку.

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

### Осталось в M2 — интеграционные тесты на живом Redis
- [ ] Инфраструктуры нет: в `phpunit.xml` два suite (`Unit`, `Feature`), `CACHE_STORE=array`,
  `QUEUE_CONNECTION=sync`, переменных `REDIS_*` нет, `.env.testing` отсутствует. Нужен третий suite поверх
  devilbox-Redis + flush-хук по аналогии с `RefreshDatabase`. Вся текущая блокировка покрыта моками.
- [ ] Не покрыты пути в обход роутера: `ResumeTimedOutSendMessageNodeJob:48` входит в движок мимо `MessageRouter`
  (guard берёт лок сам — поведение корректное, но без теста).

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

Спека: [[specs/flow-engine/nodes/07-emit-event]]

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

**Когда:** после выкладки релизного трека, параллельно M12. Не блокирует релиз.

**Почему важно:** цепочка `input`-нод собирает анкету по одному полю за сообщение. Это долго для контакта,
громоздко в builder'е и ограничено валидацией «одно поле за раз». Форма закрывает тот же сценарий одним экраном:
нормальные контролы, клиентская и серверная валидация по одной схеме, правки до отправки, приемлемый внешний вид.

**Решение о границах: Core, не Solution.** Форма это новая нода flow engine, новый путь возобновления сессии
по внешнему событию и bespoke override в builder'е. Всё три вещи доступны только Core: Solution регистрирует
`ActionHandler` для `call`-ноды, а не новые типы нод, Vue-компоненты Solution'ов требуют M7 publish contract,
которого ещё нет. Solution'ы поверх M9 остаются возможными: готовые шаблоны форм и маппинг ответов в свои
данные (`hr.*`, `crm.*`).

**Что уже есть в коде:** каркас TMA (`routes/tma.php`, `resources/js/tma` с роутером и `TmaFormRenderer`),
`InputExpectedType` как словарь типов полей, `MediaIngestor` для файлов, `ContactWriterInterface` для записи в
контакт. `TmaFormController` и `TmaAuthMiddleware` пока заглушки: контроллер отдаёт захардкоженную анкету,
middleware вне `local` отвечает 401.

### Архитектурные решения (нужен ADR перед кодом)

1. **Хранение.** Домен `Forms` в tenant schema: `forms` (ULID, `schema` JSON, `version`, `assistant_id`).
   Сессия snapshot'ит `form_version` при отправке ссылки, как делает `flow_version`. Одна форма переиспользуется
   в разных flow.
2. **Нода `form`.** Handler отправляет сообщение с кнопкой: `web_app` для Telegram, обычная ссылка на
   hosted-страницу для WhatsApp и остальных. Сессия переходит в `waiting_input`, но ждёт не текст, а внешнее
   событие. Ответы сохраняются в переменную типа `json` по `save_to`, дополнительно опциональный маппинг
   поле → `contact.*` через `ContactWriterInterface`. Handles: `submitted`, `timeout`.
3. **Возобновление сессии.** Отдельный вход в `FlowEngine` по submission, не синтетическое `IncomingMessage`
   через `MessageRouter`: роутер классифицирует по тексту и командам, форме там делать нечего. Тот же lock
   `(tenant, contact, assistant)`, что и у входящих. Submission id как idempotency-маркер: повторная отправка
   не двигает сессию дважды.
4. **Токен ссылки.** Подписанный, привязан к `(tenant, session, node, form_version)`, с TTL. Для Telegram
   дополнительно initData HMAC и проверка, что telegram id совпадает с контактом сессии. Для hosted-страницы
   токен единственная защита, поэтому одноразовый.
5. **Валидация.** Одна схема, две проверки: renderer на клиенте, Laravel rules на сервере по той же схеме.
   Ответ сервера с ошибками по полям, клиент их показывает. Серверная проверка обязательна, клиентская
   удобство.
6. **Мультиязычность.** Label, placeholder и текст ошибок идут через content translator chain как flow content;
   `value` у select/checkbox language-agnostic, как у кнопок.
7. **Конструктор.** Редактор схемы в Filament (`FormResource`), в builder'е только выбор формы в конфиге ноды.
   Встраивать готовый open-source редактор, а не писать свой. Зависимость нужно согласовать отдельно и
   отфильтровать по лицензии: репозиторий Apache-2.0, AGPL-компоненты (Formbricks, OpnForm, HeyForm, Typebot)
   не подходят. Кандидаты под MIT: `@bpmn-io/form-js` (editor + viewer, JSON schema, framework-agnostic),
   `@formio/js` (builder + renderer, есть Vue wrapper). SurveyJS: renderer MIT, Creator коммерческий.
8. **Таймаут и брошенные формы.** Форма не заполнена в TTL токена → sweeper переводит сессию по handle
   `timeout`. Механику взять по образцу `SubflowTimeoutSweeper`.

### Фазы

- **Фаза 1, фундамент:** initData HMAC в `TmaAuthMiddleware`, домен `Forms` с миграцией и моделью, подписанный
  токен, серверная валидация по схеме.
- **Фаза 2, runtime:** `FormNodeHandler` v1, submission endpoint, resume path с idempotency, sweeper таймаута,
  `TmaFormRenderer` на реальную схему вместо заглушки, hosted-страница для не-Telegram каналов.
- **Фаза 3, авторинг:** `FormResource` в Filament с встроенным редактором, `FormConfig.vue` override в builder,
  палитра и валидация publish (нода ссылается на существующую форму).
- **Фаза 4, данные:** маппинг ответов в `contact.*`, файлы через Media, ответы в транскрипте как системное
  сообщение, экспорт ответов.

**Открытые вопросы:**
- Версионирование схемы формы при уже разосланных ссылках: старые ссылки открывают snapshot или актуальную?
- Черновики: сохранять частично заполненную форму между открытиями Mini App?
- Нужен ли `form` как trigger (форма открыта вне flow, submission стартует flow) по аналогии с `emit_event`?

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
- [ ] Первый Solution: **FaPost HR** (`hr.sync_employee`, `hr.create_assessment`)  
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

> *FaPost как surface для AI-агентов: внешний агент (Claude, ChatGPT, собственный агент арендатора)
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
6. **Ingress.** MCP-ingress stateless наравне с webhook: tenant context и текущий сервер —
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
     │                              │
     │                              └──► M10 (SaaS) ─ отдельный репо
     │
     └──► M9 (Forms) — параллельно M12, Core; шаблоны форм от Solution'ов после M7

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
- [ ] Load test: 100 concurrent sessions без state leakage (Horizon-воркеры)

---

## Связано с

- [[TASKS]] — детальные задачи реализации
- [[INDEX]] — навигация по документам
- [[PROJECT]] — описание проекта
- [[plans/flow-engine/implementation-plan]] — план реализации flow engine
