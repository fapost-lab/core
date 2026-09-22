# Conversation Logging — pipeline хранения диалогов

**Статус: реализовано.** Асинхронный pipeline логирования входящих и исходящих сообщений через сменный
backend-драйвер, домен `app/Domains/Conversation/`. Актуальное описание домена —
[`.ai/knowledge/domains/conversation/OVERVIEW.md`](/.ai/knowledge/domains/conversation/OVERVIEW.md).

Два известных расхождения с диаграммой/спекой ниже (зафиксированы как trade-off, не как баг):

- `ConversationReaderInterface` не реализован — read-порта нет. Filament-инбокс
  (`app/Filament/Assistant/Resources/Conversations`) читает Eloquent-модели `Conversation` /
  `ConversationMessage` напрямую.
- Дедуп-индекс `conversation_messages_idem_unique` включает `created_at` как колонку partition-key
  (Postgres требует это для уникального индекса на партиционированной таблице):
  `(conversation_id, direction, idempotency_key, created_at)`.

```mermaid
flowchart TD
    subgraph INBOUND["Capture: Inbound"]
        IM[IncomingMessageJob] -->|ДО MessageRouter| CL_IN[ConversationLogger::log\nMessageLogEntry\ndirection=inbound]
    end

    subgraph OUTBOUND["Capture: Outbound"]
        MS[MessageSender::send] -->|ПОСЛЕ DeliveryResult| CL_OUT[ConversationLogger::log\nMessageLogEntry\ndirection=outbound]
    end

    CL_IN --> ASYNC
    CL_OUT --> ASYNC

    subgraph ASYNC["Async write path (non-blocking)"]
        ASYNC_DISPATCH[dispatch PersistConversationMessageJob\nна очередь messaging.logging]
    end

    ASYNC_DISPATCH --> WORKER

    subgraph WORKER["Horizon Worker — messaging.logging"]
        PERSIST[PersistConversationMessageJob::handle]
        PERSIST --> ENSURE[ConversationStore::ensureConversation\ntenant+assistant+contact+channel → агрегат]
        ENSURE --> APPEND[ConversationStore::append\nMessageLogEntry → conversation_messages]
    end

    subgraph STORE["Storage Drivers (сменный backend)"]
        ELOQUENT[EloquentConversationStore\nPostgreSQL, monthly partitions\ntenant-схема]
        CLICKHOUSE[ClickHouseConversationStore\nClickHouse, tenant_id в ключе\nбез schema-per-tenant]
    end

    APPEND --> ELOQUENT
    APPEND -.->|"по конфигу\n(будущее)"| CLICKHOUSE

    subgraph MEDIA["Media handling"]
        MEDIA_IN[MediaIngestor] -->|media descriptor| ELOQUENT
        Note1["media ссылки — в jsonb поле\nconversation_messages.media"]
    end

    subgraph READ["Read path"]
        INBOX[Filament ConversationResource\nинбокс оператора]
        INBOX -->|"Eloquent напрямую"| ELOQUENT
        READER["ConversationReaderInterface\n(не реализован)"]
        READER -.->|"нужен до второго backend"| CLICKHOUSE
    end
```

## Архитектурные порты

| Интерфейс | Кто использует | Что делает |
|-----------|---------------|-----------|
| `ConversationLoggerInterface` | Capture-сайты: `IncomingMessageJob`, `MessageSender` | Write-порт: принимает `MessageLogEntry`, **fire-and-forget** |
| `ConversationStoreInterface` | `PersistConversationMessageJob` | Backend-порт: `ensureConversation()`, `append()`, `updateStatus()` |
| `ConversationReaderInterface` | — | **Не реализован.** Задуманный read-порт (`list()`, `get()`, `findConversation()`); инбокс читает Eloquent-модели напрямую |

## Важные инварианты

- **Non-blocking capture:** `ConversationLogger::log()` только диспатчит job — никогда не блокирует hot path
- **Чтение идёт через Eloquent:** read-порта нет, `ConversationResource` читает `Conversation` / `ConversationMessage` напрямую. Порт записи сменный, порт чтения — нет: второй backend (ClickHouse) потребует сначала read-порт
- **Capture-порядок:** inbound логируется ДО `MessageRouter`, outbound — ПОСЛЕ получения `DeliveryResult` (успех/провал фиксируется)
- **Один тред = Conversation:** агрегат по ключу `(tenant_id, assistant_id, contact_id, channel_id)` создаётся один раз, сообщения добавляются в него
- **ClickHouse-ready:** PostgreSQL driver — дефолт, ClickHouse добавляется сменой binding без изменения capture-кода
- Очередь `messaging.logging` изолирована от `messaging.transactional` — медленный logging worker не влияет на ответы бота

## Связано с
- [[specs/messaging/conversation-logging|Спека Conversation Logging]]
- [[09-message-routing-concurrency|ADR-09]] — capture site: IncomingMessageJob
- [[01-webhook-pipeline]] — место захвата inbound
- [[11-logging-retention]] — logging и retention
- [[ROADMAP]] — дорожная карта платформы
