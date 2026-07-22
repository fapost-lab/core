# Conversation Logging — система хранения диалогов

**Статус:** Draft (дизайн, не реализовано)
**Дата:** Июнь 2026
**Контекст:** FAPost Core — продуктовый транскрипт переписки контакта с ассистентом.
**Связанные документы:** `docs/platform/architecture/adr/09-message-routing-concurrency.md`,
`docs/platform/runtime/flow/04-data-model-reference.md`,
`docs/platform/architecture/platform/10-message-pipeline.md`.

---

## 1. Зачем

Сейчас в платформе **нет полноценной истории переписки**. Что есть:

| Слой | Назначение | Retention | Почему не подходит как транскрипт |
|------|-----------|-----------|-----------------------------------|
| `flow_logs` | per-node execution движка | 30 дней, партиции | Это диагностика движка, а не «что написал юзер / что ответил бот». Умирает через месяц. |
| `flow_session_history` | переходы внутри сессии (opt-in `logging_enabled`) | без лимита | Граф нод, не сообщения. Привязан к сессии, а не к контакту. |
| `analytics_events` | агрегированные бизнес-события | постоянно | Только счётчики (flow_started/…), без контента. |
| `contacts.attributes` / `flow_sessions.state` | данные собранные `input`/`assign` | — | Только то, что явно сохранила нода. Сообщение «мимо инпута» нигде не лежит. |

Нужен **отдельный продуктовый слой** — непрерывный транскрипт диалога (входящие от контакта + исходящие от бота),
независимый от жизненного цикла flow-сессии, переживающий смену flow и доступный для:

- аудита и разбора инцидентов («что реально написал пользователь»);
- будущего **Inbox / live-chat / human takeover** (оператор видит переписку и может вмешаться).

Это **новая доменная концепция** — её нет в `CLAUDE.md`. Документ фиксирует дизайн до реализации.

---

## 2. Принятые решения (зафиксировано с владельцем продукта)

1. **Двухуровневая модель: агрегат `Conversation` + сообщения `conversation_messages`.**
   Сразу закладываем фундамент под Inbox (статус open/closed, `last_message_at`, unread, `sender = staff`),
   и одновременно это и есть аудит-транскрипт.

2. **Логируем всё:** входящие от контакта, исходящие из flow, рассылки (broadcast) по-сообщенчно,
   сообщения от notify, в будущем — staff-сообщения при takeover.

3. **Постоянное хранение + сменный backend.** Хранилище прячется за портом (интерфейсом). Дефолтный драйвер —
   PostgreSQL (tenant-схема, партиции по месяцам). По конфигу драйвер меняется на **ClickHouse** (или аналог)
   без переписывания pipeline. Запись — **асинхронная**, чтобы не блокировать hot path и естественно ложиться
   под column-store с батчингом.

### Следствие decision №3 — главный архитектурный инвариант

> **Никакой код вне драйвера хранилища не знает, где физически лежат сообщения.**
> Capture-сайты эмитят нормализованный `MessageLogEntry` в порт записи. Read-сайты (Filament, будущий Inbox)
> читают через порт чтения. Eloquent-связи на `conversation_messages` **запрещены** — иначе переключение на
> ClickHouse сломает UI.

ClickHouse живёт **вне** schema-per-tenant модели Postgres: своя БД, `tenant_id` в ключе сортировки. Для лога это
нормально — он не участвует в транзакциях ядра. Но именно поэтому и запись, и чтение идут только через абстракцию.

---

## 3. Размещение в доменах

Новый bounded context — домен **`Conversation`** (`app/Domains/Conversation/`). Это отдельный контекст: у него
свой агрегат, своё хранилище-порт и будущий Inbox-surface. Он **проекция** над трафиком Messaging, но с собственной
идентичностью.

> ⚠ Добавляет запись в список доменов в `CLAUDE.md` (Tenancy, Flow, Messaging, Contact, Assistant, Broadcasting →
> **+ Conversation**). Зафиксировать при реализации.

```
app/Domains/Conversation/
  Contracts/
    ConversationLoggerInterface.php       — write-порт (capture-сайты пишут сюда)
    ConversationStoreInterface.php        — низкоуровневый backend-порт (append + ensure + updateStatus)
    ConversationReaderInterface.php        — read-порт (Filament / Inbox)
  DTO/
    MessageLogEntry.php                    — нормализованный конверт одного сообщения
    ConversationRef.php                    — (tenant, assistant, contact, channel) → идентичность треда
  Services/
    ConversationLogger.php                 — реализация write-порта: ensure conversation + async dispatch
  Jobs/
    PersistConversationMessageJob.php      — очередь messaging.logging, вызывает Store
  Store/
    Postgres/
      PostgresConversationStore.php        — дефолтный драйвер (append + upsert агрегата)
      PostgresConversationReader.php
      ConversationPartitionManager.php     — месячные партиции (аналог FlowLogPartitionManager)
    ClickHouse/                            — будущий драйвер, тот же порт
  Models/
    Conversation.php                       — Eloquent (только для Postgres-драйвера + Filament)
    ConversationMessage.php                — read-only Eloquent surface (Postgres)
  Enums/
    MessageDirection.php  (Inbound|Outbound)
    MessageSenderType.php (Contact|Assistant|Staff|System)
    MessageOrigin.php     (Flow|Broadcast|Notify|Command|System|Staff)
    ConversationStatus.php (Open|Closed|Snoozed)
  Providers/
    ConversationServiceProvider.php        — биндинг порта на драйвер по config
```

**Где живёт `MessageLogEntry` DTO.** В домене `Conversation` (Core-internal). В Foundation **не выносим** — это не
extension-контракт, расширения сюда не пишут. Capture-сайты в Core (Webhook, Messaging, Broadcasting) импортируют
из Core свободно.

---

## 4. Порты (контракты)

```php
/** Write-порт. Вызывается из capture-сайтов. Non-blocking, никогда не бросает в вызывающего. */
interface ConversationLoggerInterface
{
    /** Записать одно сообщение в транскрипт (резолвит/создаёт conversation, диспатчит persist). */
    public function log(MessageLogEntry $entry): void;

    /** Обновить статус доставки исходящего по provider_message_id (WhatsApp delivered/read). */
    public function updateDeliveryStatus(string $providerMessageId, DeliveryStatus $status): void;
}

/** Низкоуровневый backend-порт. Реализуется каждым драйвером (Postgres, ClickHouse). */
interface ConversationStoreInterface
{
    /** Гарантировать существование треда, вернуть его id. Идемпотентно по ConversationRef. */
    public function ensureConversation(ConversationRef $ref): string;

    /** Добавить сообщение. Идемпотентно по (conversation_id, direction, idempotency_key). */
    public function appendMessage(string $conversationId, MessageLogEntry $entry): void;

    public function updateStatus(string $providerMessageId, DeliveryStatus $status): void;
}

/** Read-порт. Filament-инспектор и будущий Inbox. Без Eloquent-связей наружу. */
interface ConversationReaderInterface
{
    /** @return list<ConversationSummary> лента тредов ассистента (для inbox-списка). */
    public function listConversations(ConversationQuery $query): array;

    /** @return list<MessageView> сообщения треда, страничный курсор по created_at. */
    public function messages(string $conversationId, MessagePage $page): array;
}
```

`MessageLogEntry` (нормализованный конверт — единый и для inbound, и для outbound):

```php
final readonly class MessageLogEntry
{
    public function __construct(
        public string $tenantId,
        public string $assistantId,
        public string $contactId,
        public string $channelId,
        public string $platform,
        public MessageDirection $direction,     // Inbound | Outbound
        public MessageSenderType $senderType,    // Contact | Bot | Staff | System
        public string $contentType,              // text|photo|document|video|voice|audio|location|contact|callback|keyboard
        public ?string $text,
        public array $payload,                   // buttons, callback {value,button_id}, location, captions
        public array $media,                     // [{provider_file_id, kind, mime, file_name, size}]
        public ?string $providerMessageId,
        public ?string $replyToProviderMessageId,
        public MessageOrigin $origin,            // Flow | Broadcast | Notify | Command | System | Staff
        public array $originRef,                 // {flow_session_id, node_id} | {broadcast_id} | {staff_user_id}
        public ?string $idempotencyKey,
        public DateTimeImmutable $occurredAt,
        public ?string $senderStaffUserId = null,
    ) {}
}
```

---

## 5. Модель данных (Postgres-драйвер по умолчанию)

### `conversations` — агрегат (тред)

Один тред на кортеж (tenant, assistant, contact, channel). Денормализованные поля — для inbox-ленты без скана
сообщений.

| Поле | Тип | Назначение |
|------|-----|-----------|
| `id` | uuid (ULID) PK | |
| `tenant_id` | uuid | |
| `assistant_id` | uuid FK | |
| `contact_id` | uuid FK, cascadeOnDelete | удаление контакта → удаление треда (GDPR) |
| `channel_id` | uuid FK | |
| `platform` | string | telegram / whatsapp |
| `status` | string | open / closed / snoozed (inbox) |
| `owner_type` | string null | bot / staff — кто «ведёт» тред (takeover) |
| `owner_staff_user_id` | uuid null | |
| `last_message_at` | timestamptz | сортировка inbox |
| `last_inbound_at` | timestamptz null | |
| `last_outbound_at` | timestamptz null | |
| `last_message_preview` | string null | сниппет для ленты |
| `unread_count` | int default 0 | для staff-inbox |
| `message_count` | bigint default 0 | |
| `meta` | jsonb | |
| `created_at` / `updated_at` | timestamptz | |

`unique(tenant_id, assistant_id, contact_id, channel_id)` — один тред на связку.

### `conversation_messages` — сообщения (партиции по месяцам)

Партиционирование по `created_at` (паттерн `flow_logs`: PK включает ключ партиции, без жёсткого FK через границу
партиций). Партиции — даже при постоянном retention: ради производительности и опции точечной обрезки.

| Поле | Тип | Назначение |
|------|-----|-----------|
| `id` | uuid (ULID) | PK = (id, created_at) |
| `tenant_id` | uuid | |
| `conversation_id` | uuid | логическая ссылка на тред |
| `contact_id` / `assistant_id` / `channel_id` | uuid | денормализация для выборок |
| `direction` | string | inbound / outbound |
| `sender_type` | string | contact / assistant / staff / system |
| `sender_staff_user_id` | uuid null | takeover |
| `content_type` | string | канонический (см. §6 маппинг) |
| `text` | text null | |
| `payload` | jsonb null | клавиатуры, callback {value, button_id}, координаты, контакт-карточка, captions |
| `media` | jsonb null | ссылки на медиа: `[{media_file_id, kind, mime, file_name, size, provider_file_id, status}]` (см. §6.1) |
| `provider_message_id` | string null | id сообщения у Telegram/WhatsApp |
| `reply_to_provider_message_id` | string null | |
| `status` | string null | outbound: queued/sent/delivered/read/failed; inbound: received |
| `error` | jsonb null | при failed |
| `origin` | string | flow / broadcast / notify / command / system / staff |
| `origin_ref` | jsonb null | {flow_session_id, node_id} \| {broadcast_id} |
| `idempotency_key` | string null | дедуп |
| `created_at` | timestamptz | provider-время для inbound, время отправки для outbound |

**Индексы:**
- `(conversation_id, created_at)` — чтение треда.
- `(tenant_id, contact_id, created_at)` — переписка контакта по всем тредам.
- `(provider_message_id)` — апдейт статуса доставки.
- `unique(conversation_id, direction, idempotency_key) where idempotency_key is not null` — идемпотентность.

---

## 6. Маппинг content_type (нормализация)

Единый канонический словарь поверх существующих enum-ов. Capture-сайт переводит платформенный/доменный тип
в канонический:

| Канонический | Из inbound (`IncomingMessageType`) | Из outbound (`SendMessageContentType` / `OutgoingMessageType`) |
|--------------|-----------------------------------|---------------------------------------------------------------|
| `text` | `Text` | `Text`, `TextWithKeyboard` |
| `photo` | `Photo` | `Image` |
| `document` | `Document` | `Document` |
| `video` | `Video` | `Video` |
| `voice` | `Voice` | `Voice` |
| `audio` | `Audio` | — |
| `location` | `Location` | — |
| `contact` | `Contact` | — |
| `callback` | `CallbackQuery` | — |
| `keyboard` | — | (клавиатура едет в `payload`, отдельным типом не выделяем) |

Нажатие кнопки (`CallbackQuery`) пишется как `content_type=callback`, в `payload`:
`{ "value": "<business value>", "button_id": "<uuid>" }`. `value` — language-agnostic (как в доменной модели кнопок).

### 6.1 Хранение медиа — переиспользуем домен Media

> В платформе **уже есть полноценный медиа-слой** (`app/Domains/Media/`), закрывающий наши требования. Параллельную
> схему «байты на диск» не строим — переиспользуем существующий. (Дефолтный диск Laravel — это ровно то, на чём он
> и работает, но с tenant-изоляцией и дедупом поверх.)

Что уже готово:

| Механизм | Класс / таблица | Что даёт логу |
|----------|-----------------|---------------|
| Скачивание у провайдера по `file_id` + persist | `MediaIngestor::ingestFromChannel(Channel, providerFileId)` | inbound-вложение качается (Telegram `getFile` / WhatsApp Media API) и кладётся в хранилище. Решает «`file_id` протухает». |
| Физическое хранилище с дедупом | `media_blobs` (`content_hash` SHA-256, `unique(tenant_id, content_hash)`) | один блоб на одинаковые байты; повтор картинки не плодит файлы. |
| Per-tenant диск local/S3 | `TenantMediaDisk` + `StoragePathFactory` (`tenants/{tenant}/{kind}/{yyyy-mm}/{shard}/{ulid}.{ext}`) | это **и есть** дефолтный диск Laravel с tenant-изоляцией; Octane-safe (`Storage::build()` на вызов). |
| Кэш provider `file_id` | `media_channel_refs` (`unique(blob_id, channel_id)`, TTL-aware) | outbound: повторная отправка медиа переиспользует `file_id`, без переаплоада. |

Поэтому:

- **Inbound медиа:** `FetchConversationMediaJob` вызывает `MediaIngestor::ingestFromChannel($channel, $providerFileId)`
  → получает `MediaFile`. В `conversation_messages.media` (jsonb) храним **ссылку**, не байты:
  ```json
  [{ "media_file_id": "<ulid>", "kind": "photo", "mime": "image/jpeg",
     "file_name": "IMG_2024.jpg", "size": 184213, "provider_file_id": "AgAC..." }]
  ```
  Диск/путь/дедуп — целиком на стороне Media-домена (через `media_file_id → blob`).
- **Outbound медиа:** `media_file_id` уже известен (`SendMessageNodeHandler` / `FlowMessageSender` шлют из
  `media_files`) — просто пишем ссылку. Provider `file_id` и так закэширован в `media_channel_refs`.
- **Скачивание асинхронно:** строка сообщения пишется сразу (media-дескриптор со `status: pending`, без
  `media_file_id`); `FetchConversationMediaJob` (очередь `messaging.logging`) досоздаёт ссылку. Failed download →
  `status: failed` + остаётся `provider_file_id`, текст уже в транскрипте.

**Открытый момент — засорение медиа-библиотеки.** `MediaIngestor` создаёт `MediaFile` с `MediaSource::InputNode`.
Логирование всех входящих затопит admin-библиотеку. Решение (подтвердить при реализации): добавить
`MediaSource::Conversation` и отфильтровать его в `MediaService` (библиотека показывает только `Upload`).
Альтернатива — низкоуровневый ingest на уровне `media_blobs` без создания `MediaFile`-entity (тогда лог ссылается на
`blob_id`). Вынесено в §14.

---

## 7. Точки захвата (capture-сайты)

Привязка к реальным классам (см. карту pipeline).

### 7.1 Inbound — входящее от контакта

**Где:** `IncomingMessageJob` (`app/Domains/Webhook/Jobs/IncomingMessageJob.php`) — после нормализации
`IncomingMessage` и резолва Contact/Assistant/Channel, **до** `MessageRouter::route()`.

**Почему до router:** сообщения, которые drop-policy отбрасывает при concurrency (юзер дописал второе сообщение),
— это **реальные сообщения пользователя**, их надо сохранить. Лог не должен зависеть от того, исполнился ли flow.

- `idempotency_key` = `InboundWebhookPayload.idempotencyKey` (тот же `update_id`, что и в Redis-дедупе ingress).
- `sender_type = Contact`, `direction = Inbound`, `origin = Flow` (или `Command`, если это global command).
- Медиа из `IncomingMessage.media` (`IncomingMedia`: providerFileId, kind, mime, fileName, size) → в `media`.

### 7.2 Outbound — исходящее (flow, transactional)

**Где:** `MessageSender::send()` (`app/Domains/Messaging/MessageSender.php`) — после `DeliveryResult` с
`providerMessageId`. Единственная воронка исходящих → один capture-сайт покрывает flow + notify + broadcast.

**Контекст для лога** едет в `OutboundMessage.metadata` (уже существует): `contact_id`, `assistant_id`,
`origin`, `origin_ref` (`flow_session_id`, `node_id` или `broadcast_id`). `SendMessageNodeHandler` и
`BroadcastSendJob` заполняют metadata при сборке `OutboundMessage`.

- `idempotency_key` = `OutboundMessage.idempotencyKey` (**уже существует** — не нужен новый ключ).
- `direction = Outbound`, `sender_type = Assistant`, `status = sent` (или `failed` при `DeliveryResult.sent = false`).
- `provider_message_id` = `DeliveryResult.providerMessageId`.
- Дубликаты (`DeliveryResult.duplicate = true`) логируются идемпотентно — повторная запись схлопывается по
  unique-индексу.

### 7.3 Broadcast

`BroadcastSendJob` идёт через тот же `MessageSender` → логируется автоматически с `origin = Broadcast`,
`origin_ref = {broadcast_id}`. Один job = один получатель = одно сообщение — объём естественно линеен.
Запись асинхронная и батчится драйвером, hot path рассылки не страдает.

### 7.4 Notify (Contacts mode)

`NotifyNodeHandler` Contacts-режим → `SendContactNotificationJob` → `BroadcastSendJob` → `MessageSender`.
Покрыт §7.2/7.3 автоматически, `origin = Notify`.

> Staff-режим notify (in-app / email уведомления операторам) **не пишется** в conversation — это не сообщение
> контакту, а внутреннее уведомление платформы.

### 7.5 Delivery status (WhatsApp)

WhatsApp шлёт статус-вебхуки (sent/delivered/read). Inbound status-webhook → резолв сообщения по
`provider_message_id` → `ConversationLoggerInterface::updateDeliveryStatus()`. Telegram read-receipt'ов не даёт —
статус остаётся `sent`.

### 7.6 Staff takeover (будущее)

Оператор отвечает контакту из Inbox → исходящее с `sender_type = Staff`, `origin = Staff`,
`sender_staff_user_id`. Та же воронка `MessageSender`. Зарезервировано моделью, UI — отдельная фича.

---

## 8. Поток записи

```
[Inbound]  IncomingMessageJob ──┐
[Outbound] MessageSender ────────┤  MessageLogEntry
[Broadcast]BroadcastSendJob ─────┘        │
                                          ▼
                        ConversationLoggerInterface::log()
                                          │  (non-blocking, swallow+log при ошибке —
                                          │   как DefaultHistoryWriter)
                                          ▼
                          ConversationLogger (scoped, tenant-aware)
                                          │  dispatch
                                          ▼
        PersistConversationMessageJob  →  queue: messaging.logging  (LOW priority)
                                          │
                                          ▼
                        ConversationStoreInterface  (драйвер по config)
                          ├─ PostgresConversationStore:  ensure conversation (upsert агрегат) + insert message
                          └─ ClickHouseConversationStore: батч-insert (async_insert)
```

**Почему отдельная очередь, а не синхронная запись:**
- hot path (ответ в диалоге) не ждёт лог;
- backend-agnostic: для ClickHouse батчинг идёт в драйвере/буфере, для Postgres — дешёвый прямой insert;
- failure лога не валит обработку сообщения.

**Octane:** все capture-сайты — в worker'ах (не в ingress-контроллере). `ConversationLogger` — `scoped`,
без mutable-синглтонов. Диспатч job безопасен под Octane.

> **Решено:** отдельная очередь `messaging.logging` (LOW priority). Существующие очереди не переиспользуем — лог
> изолируется, чтобы его объём (особенно broadcast) не конкурировал за worker'ов с транзакционным трафиком.
> При реализации добавить очередь в Horizon-конфиг + supervisor.

### Масштабная запись (ClickHouse-драйвер)

`PersistConversationMessageJob` для ClickHouse-драйвера может писать не по одному, а через буфер (Redis stream /
`async_insert` ClickHouse) с батч-флашем. Это деталь драйвера — порт `ConversationStoreInterface` не меняется.
Для V1 (Postgres) — прямой insert, без буфера.

---

## 9. Retention и приватность

- **По умолчанию — постоянное хранение** (решение владельца). `retention_days = null`.
- Партиции по месяцам — ради перформанса и **опции** обрезки (если tenant позже захочет TTL — drop старых партиций,
  как `flow_logs`). Per-tenant override через `tenant_settings` (поле `conversation_retention_days`, опционально).
- **GDPR-стирание:** удаление контакта → `cascadeOnDelete` чистит `conversations` + сообщения. Медиа **не** удаляем
  слепо по пути: блобы дедуплицированы (`media_blobs` шарится между `media_file`), поэтому стираем ссылки
  (`media_file`), а физический блоб сносит refcount-GC, только если на него больше никто не ссылается. Для ClickHouse —
  `ALTER TABLE … DELETE WHERE contact_id = …` (мутация), медиа-чистка та же (через Media-домен).
- **PII:** текст хранится verbatim. Поле-уровневое шифрование / редакция — **точка расширения**, не V1. Заложить
  hook в драйвере (`MessageLogEntry` проходит через опциональный `PiiRedactorInterface` перед записью).
- **Конфиг** `config/conversation.php`:
  ```php
  return [
      'driver'  => env('CONVERSATION_STORE', 'postgres'),  // postgres | clickhouse
      'enabled' => env('CONVERSATION_LOGGING', true),       // глобальный выключатель
      'retention_days' => null,                             // null = постоянно
      'queue' => 'messaging.logging',
      'media' => [
          'fetch' => true,   // качать inbound-медиа через MediaIngestor (диск/путь/дедуп — на стороне Media-домена)
      ],
  ];
  ```

---

## 10. Read surface (Filament)

- `ConversationResource` (assistant-level, **read-only**) — лента тредов + просмотр транскрипта. По смыслу рядом с
  `FlowSessionResource` / `ContactResource`.
- Читает через `ConversationReaderInterface`, **не** через Eloquent-relations — чтобы пережить смену драйвера.
- **Честный trade-off:** Filament нативно завязан на Eloquent. Для Postgres-драйвера v1 Resource опирается на
  Eloquent-модели `Conversation`/`ConversationMessage` напрямую (быстро). При переключении на ClickHouse список/
  транскрипт переедут на **custom Filament Page** поверх `ConversationReaderInterface` (page-based, не Resource).
  Это документированное ограничение, а не сюрприз.

---

## 11. Чего НЕ делаем (границы)

- Не дублируем `flow_logs` — это диагностика движка (30 дней), а не транскрипт. Разные потребители, разный retention.
- Не трогаем `flow_sessions.state` namespaces — лог не пишет в state.
- Не логируем staff-уведомления (notify Staff mode) как сообщения контакту.
- Не выносим контракты в Foundation — это не extension-точка.
- Не делаем Inbox-UI и takeover в этой итерации — только модель/места захвата под них.

---

## 12. Фазы реализации

| Фаза | Содержание |
|------|-----------|
| 1. Контракты + DTO | `Conversation` домен, порты, `MessageLogEntry`, enums. |
| 2. Postgres-драйвер | миграции `conversations` + партиционированный `conversation_messages`, `PostgresConversationStore`, `ConversationPartitionManager`, `PostgresConversationReader`. |
| 3. Async pipeline | `ConversationLogger`, `PersistConversationMessageJob`, очередь `messaging.logging` (согласовать). |
| 4. Capture inbound | hook в `IncomingMessageJob` (до router, идемпотентно по update_id). |
| 4a. Media fetch | `FetchConversationMediaJob` поверх `MediaIngestor::ingestFromChannel()` — переиспользуем Media-домен, ссылка `media_file_id` в сообщение. |
| 5. Capture outbound | контекст в `OutboundMessage.metadata` (`SendMessageNodeHandler`, `BroadcastSendJob`) + запись в `MessageSender`. |
| 6. Delivery status | WhatsApp status-webhook → `updateDeliveryStatus`. |
| 7. Filament read-only | `ConversationResource` через reader. |
| 8. (Позже) ClickHouse-драйвер, Inbox, takeover, PII-redaction. | за триггером. |

Каждая фаза — с тестами (feature-тесты на capture-идемпотентность, partition rollover, driver-swap через фейковый
драйвер).

---

## 13. Решённые вопросы

- **Очередь — отдельная `messaging.logging` (LOW).** Не переиспользуем существующие: изоляция объёма лога от
  транзакционного трафика (см. §8).
- **Тред дробим по каналам.** Ключ треда = (tenant, assistant, contact, **channel**). Контакт, пишущий одному
  ассистенту через два канала, имеет два независимых треда. `channel_id` — часть идентичности conversation, а не
  атрибут сообщения. (Соответствует `unique(tenant_id, assistant_id, contact_id, channel_id)` в §5.)
- **Хранение медиа — переиспользуем существующий Media-домен** (`MediaIngestor` + `media_blobs` + `TenantMediaDisk`,
  дефолтный диск Laravel local/S3 с tenant-изоляцией и дедупом). Лог ссылается на `media_file_id`, байты не дублируем.
  Provider `file_id` протухает (Telegram) — `MediaIngestor` качает файл в хранилище. Подробности — §6.1.

## 14. Открытые вопросы

1. **Медиа и медиа-библиотека.** `MediaIngestor` создаёт `MediaFile`, который засветится в admin-библиотеке.
   Добавить `MediaSource::Conversation` + фильтр в `MediaService`, или низкоуровневый ingest на уровне `media_blobs`
   без `MediaFile`-entity (лог ссылается на `blob_id`). См. §6.1.
2. **ClickHouse и tenant-изоляция.** Одна таблица с `tenant_id` в сортировке vs БД per tenant. Решается при
   появлении ClickHouse-драйвера.
3. **Объём broadcast.** При массовых рассылках лог растёт быстро. Постоянный retention + большие объёмы → один из
   первых кандидатов на ClickHouse-драйвер.

---

## Связано с

- [[07-media-domain-asset-cache]] — медиа в диалогах
- [[11-logging-retention]] — logging и retention
- [[05-conversation-logging]] — диаграмма
- [[10-message-pipeline]] — message pipeline
- [[diagrams/05-conversation-logging]] — диаграмма conversation logging
- [[ROADMAP]] — дорожная карта платформы
- [[TASKS]] — задачи реализации
