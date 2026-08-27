# Node · `input`

Запрашивает данные от пользователя, ждёт ответа, валидирует, сохраняет.

**Type:** `input`
**Version:** 1
**Idempotent:** yes (по входному message_id, дедупликация на webhook layer)

## Config

```json
{
  "prompt": {
    "content_type": "text",
    "text": "Введите ваше имя",
    "keyboard": null
  },
  "input_type": "text",
  "validators": [
    {"type": "min_length", "value": 2},
    {"type": "max_length", "value": 50}
  ],
  "validation_error_message": "Имя должно быть от 2 до 50 символов",
  "max_attempts": 3,
  "max_attempts_handle": "max_attempts_exceeded",
  "variable": {
    "name": "first_name",
    "storage": "contact",
    "group": null
  }
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `prompt` | SendMessagePayload | yes | Сообщение которое спрашивает (та же структура что в send_message) |
| `input_type` | enum | yes | См. список ниже |
| `validators` | array | no | Дополнительные валидаторы поверх типа |
| `validation_error_message` | Expression | no | Сообщение при ошибке валидации |
| `max_attempts` | int | no | Default 3. Максимум попыток |
| `max_attempts_handle` | string | no | Default `"max_attempts_exceeded"`. Handle при исчерпании попыток |
| `variable` | VariableDef | yes | См. [02-common-concepts.md](../02-common-concepts.md), раздел 2.1 |

## Поддерживаемые input_type

| Type | Источник данных | Валидация |
|------|------------------|-----------|
| `text` | message text | non-empty (по умолчанию) |
| `number` | message text → parse | numeric |
| `email` | message text | regex email |
| `phone` | message text \| Telegram contact share | libphonenumber |
| `contact` | Telegram contact attachment | присутствие attachment |
| `select` | callback_query | значение из keyboard в prompt |
| `confirm` | callback_query | yes/no |
| `file` | message attachment (document) | присутствие |
| `photo` | message attachment (photo) | присутствие |
| `location` | Telegram location | присутствие |
| `date` | message text + parser | parseable date (см. ниже Date and timezone handling) |

## Built-in validators

```json
{"type": "min_length", "value": 2}
{"type": "max_length", "value": 100}
{"type": "min", "value": 18}
{"type": "max", "value": 99}
{"type": "regex", "pattern": "^[А-Я][а-я]+$"}
{"type": "in", "values": ["a", "b", "c"]}
```

Custom validators (через code) — не V1.

## Output handles

- `success` — ответ получен и валиден, сохранён
- `max_attempts_exceeded` (или кастомное имя из `max_attempts_handle`) — пользователь N раз дал невалидный ответ

## Behavior

1. Engine рендерит prompt и отправляет (как send_message)
2. Engine ставит session в `waiting_input`, сохраняет
3. При входящем сообщении от того же contact — engine resume сессии
4. Engine извлекает значение из message по `input_type`:
   - text → `message.text`
   - select → `message.callback_query.data`
   - photo → `message.photo[-1].file_id` (largest)
   - и т.д.
5. Применяет type-specific validation + дополнительные validators
6. Если invalid → инкремент `system.input_attempts`, отправка `validation_error_message`, остаёмся в waiting
7. Если `system.input_attempts >= max_attempts` → переход через `max_attempts_handle`
8. Если valid → запись:
   - `storage=session` (например `flow.<name>` или `flow.<group>.<name>`) → возвращается через `result.stateChanges`, engine применяет атомарно при сохранении session
   - `storage=contact` (`contact.<name>` или `contact.<group>.<name>`) → handler вызывает `$context->contactWriter->write(...)` — immediate (см. [03-node-handler-interface.md](../03-node-handler-interface.md))
   → переход через `success`

## Date and timezone handling

> **Patch v1.1:** новый sub-block для `input_type=date`.

### Resolution chain

```
1. contact.meta.timezone (если задан webhook ingress)
2. tenant.settings.timezone (default "UTC", обязательное поле tenant settings)
3. UTC fallback
```

### Хранение в state

ISO 8601 UTC + дополнительное поле с original timezone:

```
flow.appointment    = "2026-04-28T07:00:00Z"   ← UTC normalized
flow.appointment_tz = "Europe/Moscow"           ← original timezone hint
```

Это однозначно decodable обратно с правильной timezone для display.

### Parsing

```php
final class DateInputParser
{
    public function parse(string $input, TenantInterface $tenant, ContactInterface $contact): ?CarbonImmutable
    {
        $tz = $contact->meta['timezone']
            ?? $tenant->settings['timezone']
            ?? 'UTC';

        try {
            return CarbonImmutable::parse($input, $tz)->utc();
        } catch (\Exception) {
            return null;  // → validation failure, retry input
        }
    }
}
```

### Tenant settings

`tenant.settings.timezone` становится **обязательным полем** (default `"UTC"`). Нужна migration tenants для добавления этой настройки.

### V1.1 considerations

Более robust NLP date parsing ("завтра в 10", "next monday") — отдельная библиотека (Carbon NLP, Chronos). В V1 — basic Carbon::parse достаточно. Out-of-scope (см. [09-out-of-scope-and-open-questions.md](../09-out-of-scope-and-open-questions.md)).

## Validation flow_definition

- Если `input_type=select` — `prompt.keyboard` должен быть задан (static или dynamic)
- Если `input_type=confirm` — `prompt.keyboard` игнорируется, генерируется автоматически
- `variable` resolved path должен соответствовать правилам uniqueness/structure (см. [06-validation.md](../06-validation.md), раздел 6.5)

---

## Связано с

- [[README]] — nodes README
- [[11-loop]] — Store as list флаг на input переменной
- [[00-overview]] — обзор builder/storage
- [[15-multilingual]] — захват языка контакта
- [[03-branch]] — валидация через branch после input
