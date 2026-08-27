# Node · `send_message`

Отправляет сообщение в канал доставки.

**Type:** `send_message`
**Version:** 1
**Idempotent:** в V1 ограниченно — distributed lock покрывает 99% случаев, см. ADR Message Routing & Concurrency Control. Полная Redis-based dedup — V1.x.

## Config

```json
{
  "content_type": "text",
  "text": "Привет, {{contact.first_name}}",
  "media_url": null,
  "caption": null,
  "keyboard": {
    "type": "static",
    "buttons": [
      {"text": "Да", "value": "yes", "row": 0},
      {"text": "Нет", "value": "no", "row": 0}
    ]
  }
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `content_type` | enum | yes | text / image / document / video / voice |
| `text` | Expression | conditional | Required если content_type=text. Подпись для media — в caption |
| `media_url` | Expression\|null | conditional | Required если content_type != text |
| `caption` | Expression\|null | no | Подпись к media |
| `keyboard` | KeyboardSpec\|null | no | См. ниже |

## KeyboardSpec — два режима

### Static (литеральный список кнопок)

```json
{
  "type": "static",
  "buttons": [
    {"text": "Иванов", "value": "1", "row": 0},
    {"text": "Петров", "value": "2", "row": 0},
    {"text": "Отмена", "value": "cancel", "row": 1}
  ]
}
```

### Dynamic (из коллекции в state)

```json
{
  "type": "dynamic",
  "source": "{{flow.employees}}",
  "item_template": {
    "text": "{{item.name}}",
    "value": "{{item.id}}"
  },
  "max_per_row": 2
}
```

В dynamic режиме engine итерирует по resolved коллекции (массив объектов), для каждого item рендерит template.

> **Patch v1.1:** `value` template **обязан** содержать placeholder `{{item.X}}`. Pure literal без placeholders — validation error при save flow_definition. Convention: использовать unique identifier (`{{item.id}}`, `{{item.key}}`) для надёжной идентификации выбора.
>
> Engine не делает обратный mapping `value → item object`. Если нужен полный объект — пользователь сохраняет коллекцию в state и выполняет lookup через expression после Input.

## Output handles

- `success` — сообщение отправлено
- `error` — ошибка отправки (соединение, rate limit, channel limit и т.п.)

## Behavior

> **Patch v1.2 (ADR Message Routing & Concurrency Control):** в V1 attempt-tracking + Redis SET NX dedup **не реализуется**. Distributed lock на `(tenant, contact, assistant)` обеспечивает что одна сессия не выполняется параллельно. Контракт `idempotencyKey` в `MessageSender.send()` сохранён для forward-compat — implementation просто passes значение в провайдер (HTTP — `Idempotency-Key` header), Redis dedup откладывается до V1.x.

1. Handler рендерит все Expression поля относительно текущего state
2. Handler формирует idempotency key: `idempotencyKey = "{session_id}:{node_id}:{attempt_number}"`. В V1 `attempt_number = 1` (статически — нет автоинкремента).
3. Handler вызывает `MessageSender.send(message, idempotencyKey)`
4. MessageSender:
   - Если канал поддерживает (HTTP) — кладёт `idempotencyKey` в `Idempotency-Key` header
   - Telegram игнорирует
   - Возвращает `SentMessageResult{messageId, wasDeduplicated: false, providerMetadata}` (в V1 `wasDeduplicated` всегда `false`)
5. Handler добавляет `message_id` в `system.sent_message_ids` (observability tracking)

### Known V1 limitation: worker crash mid-send

Если worker отправил сообщение провайдеру и упал перед `save session.version`, retry даст повторный send (новый worker не знает что предыдущий attempt уже выполнился). Окно crash window малое (~100ms между send и save), реальная frequency низкая.

Полное решение — Redis SET NX dedup на стороне MessageSender — V1.x по first business need (broadcast, financial calls). См. ADR Message Routing & Concurrency Control, раздел "Failure Modes / Worker crash mid-execution".

`system.sent_message_ids` остаётся append-only логом отправок для observability и отладки, **не используется для dedup**.

### MessageSender контракт

```php
interface MessageSenderInterface
{
    public function send(
        OutgoingMessage $message,
        string $idempotencyKey
    ): SentMessageResult;
}

final class SentMessageResult
{
    public function __construct(
        public readonly string $messageId,
        public readonly bool $wasDeduplicated,  // V1: всегда false (нет Redis dedup)
        public readonly array $providerMetadata,
    ) {}
}
```

### Channel limits enforcement

> **Patch v1.1:** Channel-specific limits (Telegram: 100 buttons, 8 per row, button text 64 chars; WhatsApp: свои) enforce **на уровне ChannelAdapter**, не в SendMessageHandler. Handler — graph-aware, не знает channel-specific constraints.

ChannelAdapter при send:
- Validate against channel limits
- При превышении → `ChannelMessageInvalidException`
- В `CallResult.error_code` → `"channel_limit_exceeded"`
- handle → `error`

Пользователь получает: «message не отправлено, превышен limit». Может ветвить через branch для fallback.

## Validation flow_definition

- `text` обязателен если `content_type=text`
- `media_url` обязателен если `content_type != text`
- В static keyboard — `text` и `value` непустые
- В dynamic keyboard — `source` и `item_template` непустые
- В dynamic keyboard — `value` template содержит хотя бы один placeholder `{{item.X}}` (не pure literal)

---

## Связано с

- [[README]] — nodes README
- [[../../../plans/flow-engine/implementation-plan]] — план реализации
- [[07-media-domain-asset-cache]] — медиа контент в сообщениях
- [[15-multilingual]] — локализация текстов
