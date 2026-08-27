# Flow Engine — Core Nodes V1

**Документ:** Техническая спецификация для реализации
**Версия:** 1.0
**Дата:** Апрель 2026
**Статус:** Готов к реализации
**Контекст:** FAPost Phase 2 — Flow Engine, Sprint 4–6

---

## 1. Обзор

Спецификация описывает 10 типов нод движка Flow Engine для V1. Каждая нода имеет жёсткий контракт: тип, версия, конфиг, поведение, output handles. Все handlers in-memory зарегистрированы через `NodeHandlerRegistry` (см. ADR Handler Versioning).

**Принципы:**

- Минимум типов — меньше versioning surface
- Один контракт = один node type (Branch вместо condition+switch, Assign вместо set_attribute+transform, Call вместо integration+action)
- Pluggable extension points: транспорты для Call, типы input
- UI скрывает namespace и runtime детали — пользователь видит только бизнес-концепты

---

## 2. State Model

### 2.1 Namespaces в JSON snapshot и runtime

Все nodes пишут и читают через единый набор namespace. Эти namespace **не показываются** пользователю в UI.

| Namespace | Владелец | Persistence | Назначение |
|-----------|----------|-------------|------------|
| `system.*` | Engine | session JSON | started_at, retry_count, current_node |
| `flow.*` | Compiler-generated nodes | session JSON | session-scoped scratchpad для runtime данных |
| `rag.*` | rag_query node | session JSON | found, confidence, answer, intent последнего запроса |
| `call.*` | call node | session JSON | response payload последнего вызова |
| `contact.*` | resolver через Contact модель | persistent (Contact) | Свойства контакта |
| `module.<name>.*` | DataAccessor | external (модуль) | Канонические данные модуля |

`flow_sessions.state` JSON содержит только `system`, `flow`, `rag`, `call`. `contact.*` и `module.*` резолвятся через accessors при чтении и записываются через writers — не дублируются в session state.

### 2.2 Contact namespace

Плоская адресация в expression и save_to:

```
contact.<key>                    — leaf поле в attributes
contact.<group>.<key>            — nested JSON в attributes
contact.meta.<key>               — данные платформы (read-only для пользователя)
```

Resolver при чтении `contact.<key>`:

1. Если `<key>` — известное поле модели (id, channel_id, channel) → колонка модели
2. Иначе walk по `attributes` JSON path
3. Если на пути встречается non-object value на промежуточном сегменте → null
4. Если ключ не найден → null

Resolver при записи `contact.<key>` (assign/input):

1. Если `<key>` — reserved (id, channel_id, channel, meta.*) → validation error при сохранении flow_definition
2. Иначе walk по path в `attributes`, создавая объекты по дороге
3. Если на пути встречается non-object value на промежуточном сегменте → runtime error, session failed

### 2.3 Глубина вложенности

Группы — максимум 1 уровень: `contact.<group>.<field>`.

```
contact.name                ✓
contact.form.input1         ✓
contact.form.address.city   ✗ слишком глубоко
```

Validator при сохранении flow_definition проверяет `save_to` на максимум 1 точку после `contact.`.

### 2.4 Reserved keys

**Reserved для contact:**
- Поля модели: `id`, `tenant_id`, `channel_id`, `channel`
- Группа: `meta` (owned by webhook ingress — username, first_name из платформы)

Список фиксируется в коде. Validator проверяет на сохранении.

---

## 3. Common Concepts

### 3.1 Variable definition

Используется в Input и Assign nodes. Единый формат.

```json
{
  "variable": {
    "name": "input1",
    "type": "text",
    "storage": "contact",
    "group": "form"
  }
}
```

Поля:

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `name` | string | yes | Имя переменной (alphanumeric + underscore) |
| `type` | enum | yes | См. список типов в Input ноде |
| `storage` | enum | yes | `contact` или `session` |
| `group` | string\|null | no | Имя группы (alphanumeric + underscore). null = корень |

**Compiler model — где физически хранится:**

| storage | group | Resolved path |
|---------|-------|---------------|
| `contact` | null | `contact.<name>` (in `attributes`) |
| `contact` | `form` | `contact.form.<name>` (nested in `attributes`) |
| `session` | null | `flow.<name>` (in `flow_sessions.state.flow`) |
| `session` | `params` | `flow.params.<name>` |

### 3.2 Expression

Все значения которые могут быть переменными — это `Expression`. В V1 expression это **строка с placeholders**:

```
"Привет, {{contact.first_name}}"
"{{flow.code}}"
"{{contact.form.input1}} {{contact.form.input2}}"
```

Чистая строка без placeholders — литерал. Чистый placeholder — значение по path.

**Pre-V1 решение:** конкретный expression engine (Symfony ExpressionLanguage / Twig / custom) фиксируется отдельным ADR. Спецификация ниже не зависит от выбора engine — нужны только подстановки и базовые операторы сравнения.

**Sandbox scope в expression:**
- `system.*`
- `flow.*`
- `rag.*`
- `call.*`
- `contact.*` (читается через resolver)
- `module.<name>.*` (читается через DataAccessor)

### 3.3 Output handles

Каждый node возвращает `NodeExecutionResult` с `sourceHandle` — engine резолвит next node через edge lookup. Handlers — graph-unaware.

Стандартные handles per node — см. описания нод ниже.

### 3.4 Node JSON snapshot

Базовая структура одинакова для всех нод:

```json
{
  "id": "01HQ...",
  "type": "<node_type>",
  "version": 1,
  "config": {
    /* node-specific config */
  }
}
```

`id` — ULID per node в графе flow.
`type` — типа handler в registry.
`version` — версия контракта handler.
`config` — node-specific.

---

## 4. Nodes

### 4.1 send_message

Отправляет сообщение в канал доставки.

**Type:** `send_message`
**Version:** 1
**Idempotent:** yes (через `system.sent_message_ids`)

**Config:**

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

**KeyboardSpec — два режима:**

Static (литеральный список кнопок):

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

Dynamic (из коллекции в state):

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

**Output handles:**
- `success` — сообщение отправлено
- `error` — ошибка отправки (соединение, rate limit и т.п.)

**Behavior:**
- Engine рендерит все Expression поля относительно текущего state
- Отправляет через MessageSender в очередь `messaging.transactional`
- При успехе — добавляет `message_id` в `system.sent_message_ids` (для idempotency при retry)
- При retry — engine проверяет `sent_message_ids` и не отправляет повторно

**Validation:**
- `text` обязателен если `content_type=text`
- `media_url` обязателен если `content_type != text`
- В static keyboard — `text` и `value` непустые
- В dynamic keyboard — `source` и `item_template` непустые

---

### 4.2 input

Запрашивает данные от пользователя, ждёт ответа, валидирует, сохраняет.

**Type:** `input`
**Version:** 1
**Idempotent:** yes (по входному message_id, дедупликация на webhook layer)

**Config:**

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
    "type": "text",
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
| `max_attempts_handle` | string | no | Default "max_attempts_exceeded". Handle при исчерпании попыток |
| `variable` | VariableDef | yes | См. раздел 3.1 |

**Поддерживаемые input_type:**

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
| `date` | message text + parser | parseable date |

**Built-in validators (опциональные дополнительно к типу):**

```json
{"type": "min_length", "value": 2}
{"type": "max_length", "value": 100}
{"type": "min", "value": 18}
{"type": "max", "value": 99}
{"type": "regex", "pattern": "^[А-Я][а-я]+$"}
{"type": "in", "values": ["a", "b", "c"]}
```

Custom validators (через code) — не V1.

**Output handles:**
- `success` — ответ получен и валиден, сохранён
- `max_attempts_exceeded` — пользователь N раз дал невалидный ответ

**Behavior:**

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
8. Если valid → запись в variable.storage по правилам resolver → переход через `success`

**Validation flow_definition:**
- Если `input_type=select` — prompt.keyboard должен быть задан (static или dynamic)
- Если `input_type=confirm` — prompt.keyboard игнорируется, генерируется автоматически
- variable.name должно быть уникально в рамках flow_definition (см. раздел 8)

---

### 4.3 branch

Ветвление по условиям. Объединяет classic Condition (true/false) и Switch (множественные cases).

**Type:** `branch`
**Version:** 1
**Idempotent:** yes (чистое чтение state)

**Config:**

```json
{
  "cases": [
    {
      "left": {"source": "contact", "path": "form.input1"},
      "operator": "eq",
      "right": {"type": "literal", "value": 1},
      "handle": "first"
    },
    {
      "left": {"source": "contact", "path": "form.input1"},
      "operator": "eq",
      "right": {"type": "literal", "value": 2},
      "handle": "second"
    }
  ],
  "default_handle": "other"
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `cases` | array | yes | Список условий, проверяются по порядку |
| `default_handle` | string | yes | Handle когда ни один case не сматчился |

**Case:**

| Поле | Тип | Описание |
|------|-----|----------|
| `left` | OperandRef | Левая часть |
| `operator` | enum | Оператор сравнения |
| `right` | OperandRef\|Literal | Правая часть |
| `handle` | string | Имя handle для перехода |

**OperandRef:**

```json
{"source": "contact",  "path": "form.input1"}
{"source": "flow",     "path": "code"}
{"source": "rag",      "path": "confidence"}
{"source": "call",     "path": "response.status"}
{"source": "module",   "path": "hr.department"}
```

**Literal:**

```json
{"type": "literal", "value": 42}
{"type": "literal", "value": "active"}
{"type": "literal", "value": true}
{"type": "literal", "value": null}
```

**Operators:**

| Operator | Описание | Применимость |
|----------|----------|--------------|
| `eq` | равно | любые типы |
| `neq` | не равно | любые |
| `gt` / `gte` | > / >= | numbers, dates |
| `lt` / `lte` | < / <= | numbers, dates |
| `contains` | содержит | strings, arrays |
| `not_contains` | не содержит | strings, arrays |
| `starts_with` / `ends_with` | начинается/заканчивается | strings |
| `in` / `not_in` | входит / не входит в массив | left = scalar, right = array |
| `is_empty` / `is_not_empty` | пусто / не пусто | unary, без right |
| `is_null` / `is_not_null` | null / не null | unary, без right |

**Output handles:**
- Все handles из `cases[].handle` + `default_handle`

**Behavior:**

1. Для каждого case по порядку — резолвит left и right через resolvers
2. Применяет operator
3. Первый матч — выходит через case.handle
4. Если ни один не сматчился — выходит через default_handle

**Validation flow_definition:**
- Все handles в cases должны быть unique
- default_handle не должен дублировать handle case
- Edges из ноды должны покрывать все объявленные handles (возможен warning при отсутствии edge — flow продолжает идти к default null transition)

---

### 4.4 delay

Пауза перед переходом к следующей ноде.

**Type:** `delay`
**Version:** 1
**Idempotent:** yes

**Config:**

```json
{
  "mode": "relative",
  "value": "PT1H",
  "absolute_at": null
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `mode` | enum | yes | `relative` или `absolute` |
| `value` | string\|null | conditional | ISO 8601 duration (PT1H, P1D, PT30M) — для relative |
| `absolute_at` | Expression\|null | conditional | ISO 8601 datetime — для absolute |

**Output handles:**
- `success` — после паузы

**Behavior:**

1. Resolve target time:
   - relative: now() + parsed duration
   - absolute: parse Expression as datetime
2. Schedule delayed job в `flow.execution` queue с `delay = target - now()`
3. Сохраняет session в статусе `paused`, `expires_at` обновляется на target если оно позже текущего
4. Когда delayed job выполняется — проверяет `current_node_id == this_node_id`. Если другая нода — return (concurrency safety, см. раздел 5.3 архитектуры)
5. Resume через `success` handle

**Validation flow_definition:**
- Ровно одно из `value` / `absolute_at` задано
- ISO 8601 формат корректен

**Logging:** delay execution **не логируется** в flow_logs (раздел 16 plan v2 — что не логировать).

---

### 4.5 assign

Запись значений. Объединяет SetAttribute и Transform.

**Type:** `assign`
**Version:** 1
**Idempotent:** yes

**Config:**

```json
{
  "operations": [
    {
      "target": "contact.full_name",
      "value": "{{contact.first_name}} {{contact.last_name}}"
    },
    {
      "target": "flow.code_attempts",
      "value": "{{flow.code_attempts}} + 1"
    },
    {
      "target": "contact.form.completed_at",
      "value": "{{system.now}}"
    }
  ]
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `operations` | array (>=1) | yes | Список операций, выполняются по порядку |

**Operation:**

| Поле | Тип | Описание |
|------|-----|----------|
| `target` | string | Полный path куда писать (`contact.X`, `contact.group.X`, `flow.X`) |
| `value` | Expression | Выражение для вычисления значения |

**Output handles:**
- `success`

**Behavior:**

1. Для каждой operation по порядку:
   - Resolve `value` Expression → значение
   - Apply через resolver writer:
     - `contact.*` → запись в Contact (model column или attributes JSON)
     - `flow.*` → запись в `flow_sessions.state.flow`
   - Если writer бросает (например, тип несовместим) → session failed
2. После всех operations — сохранение session с version bump
3. Переход через `success`

**Validation flow_definition:**
- target начинается с `contact.` или `flow.` (другие namespaces — read-only для пользователя)
- target не нарушает reserved keys (раздел 2.4)
- target не превышает глубину 1 уровень группы для contact

---

### 4.6 call

Вызов внешнего или внутреннего сервиса. Объединяет Integration (HTTP) и Action (in-process).

**Type:** `call`
**Version:** 1
**Idempotent:** yes (через `CallContext.idempotencyKey`)

**Config:**

```json
{
  "transport": "http",
  "target": "POST https://api.example.com/users",
  "parameters": {
    "body.first_name": "{{contact.first_name}}",
    "body.email":      "{{contact.email}}",
    "auth.bearer":     "{{system.secrets.api_token}}"
  },
  "transport_options": {
    "timeout": 30,
    "headers": {
      "Content-Type": "application/json"
    },
    "retry": {
      "max_attempts": 3,
      "backoff": "exponential"
    }
  },
  "result_mapping": {
    "flow.created_user_id": "payload.data.id",
    "flow.last_call_status": "metadata.status_code"
  }
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `transport` | string | yes | ID транспорта в `CallTransportRegistry`. Built-in: `http`, `handler` |
| `target` | Expression | yes | Семантика target зависит от транспорта |
| `parameters` | object | no | Map: <key> → Expression. Resolved при выполнении |
| `transport_options` | object | no | Transport-specific опции (raw passthrough) |
| `result_mapping` | object | no | Map: target_path → result_path. Куда положить результат в state |

**Семантика target по транспорту:**

| Transport | target |
|-----------|--------|
| `http` | `<METHOD> <URL>`, например `POST https://api.example.com/users` |
| `handler` | ID action handler в `ActionHandlerRegistry`, например `crm.sync_contact` |

**Output handles:**
- `success` — вызов выполнен успешно
- `error` — ошибка транспорта или action handler

**Behavior:**

1. Resolve `transport` (literal, не Expression — выбор транспорта runtime запрещён)
2. Получить транспорт из `CallTransportRegistry`. Если нет — session failed
3. Resolve `target` через Expression
4. Resolve `parameters` (recursively, каждое значение)
5. Построить `CallRequest{target, parameters, options=transport_options}`
6. Построить `CallContext{tenant, session, idempotencyKey = sha1(session.id + node.id + attempt_number)}`
7. Вызвать `transport.execute(request, context)` — возвращает `CallResult`
8. Если result.success && result_mapping задан — apply mapping:
   - Для каждой пары `target_path → result_path`:
   - Извлечь значение из CallResult по `result_path` (например, `payload.data.id` → result.payload['data']['id'])
   - Записать в state по `target_path`
9. Сохранить session с version bump
10. Переход:
    - `success` если result.success
    - `error` иначе (включая исключения транспорта)

**Built-in транспорты:**

`http` — HTTP клиент с поддержкой:
- Methods: GET, POST, PUT, DELETE, PATCH
- parameters keys: `body.*`, `query.*`, `headers.*`, `auth.bearer`, `auth.basic.username`, `auth.basic.password`
- Response в CallResult.payload (parsed JSON если Content-Type=application/json, иначе raw string)
- Idempotency key пишется в header `Idempotency-Key`

`handler` — in-process вызов из `ActionHandlerRegistry`:
- target = action ID
- parameters передаются как массив в `ActionHandlerInterface::handle($parameters, $context)`
- Возврат action handler → CallResult.payload
- Idempotency key передаётся через CallContext

**Validation flow_definition:**
- transport ∈ ids зарегистрированных транспортов
- target формат соответствует транспорту (для http — VALID METHOD + URL pattern)
- result_mapping target_path начинается с `flow.` или `contact.`

---

### 4.7 emit_event

Async генерация события для запуска других flow через triggers.

**Type:** `emit_event`
**Version:** 1
**Idempotent:** yes (event publishing — append-only)

**Config:**

```json
{
  "event_type": "order.created",
  "payload": {
    "order_id": "{{flow.order_id}}",
    "amount": "{{flow.amount}}"
  }
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `event_type` | string | yes | Имя события (literal, не Expression) |
| `payload` | object | no | Map ключей → Expressions, resolved перед публикацией |

**Output handles:**
- `success`

**Behavior:**

1. Resolve payload через Expressions
2. Publish event через FlowTriggerEventBus:
   - tenant_id (из session)
   - event_type
   - payload (resolved)
   - source: {flow_id, session_id, node_id}
3. Engine продолжает с `success` handle. **Не ждёт** обработки события.
4. Triggers с `type=event, event_type=<this>` запустят свои flow асинхронно (в `scheduled.triggers` queue)

**Validation flow_definition:**
- event_type non-empty, alphanumeric + dot

---

### 4.8 subflow

Вызов другого flow с приостановкой parent до завершения child.

**Type:** `subflow`
**Version:** 1
**Idempotent:** complex (см. behavior)

**Config:**

```json
{
  "flow_id": "01HQ_collect_personal_data",
  "flow_version": null,
  "timeout": "PT24H"
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `flow_id` | string | yes | ULID logical flow id (НЕ flow_definition_id) |
| `flow_version` | int\|null | no | Конкретная version. null = latest active на момент вызова |
| `timeout` | string | yes | ISO 8601 duration. По истечении child force-failed |

**Output handles:**
- `success` — child завершён через end node со status=success
- `cancelled` — child завершён через end со status=cancelled
- `failed` — child failed unexpectedly, либо timeout, либо end со status=failed

**V1 ограничения:**

- **Без параметров:** parent НЕ передаёт child input. Child НЕ возвращает parent output. Координация — через `contact.*` или модульные данные.
- **Один child за раз:** parent в `paused_subflow` не может вызвать ещё один subflow параллельно. Subflow всегда sequential.
- **Глубина max 3 уровня:** A → B → C допустимо. Глубже refuse при сохранении flow_definition.
- **Direct и indirect recursion запрещены:** flow A → A или A → B → A — refuse при сохранении.
- **Wait mode only:** fire-and-forget делается через `emit_event`, не subflow.

**Behavior:**

1. Resolve `flow_id` и `flow_version`. Если version=null — взять активную версию flow_definition. Если flow не найден / нет активной версии → session failed.
2. INSERT child flow_session:
   - `parent_session_id` = parent.id
   - `parent_resume_node_id` = subflow node id
   - `flow_definition_id` = резолвленный snapshot id
   - `state.flow` = {} (пустой)
   - `state.system.started_at` = now()
   - `expires_at` = now() + timeout
   - status = `active`
3. UPDATE parent_session:
   - status = `paused_subflow`
   - version = version + 1
4. Передать distributed lock от parent к child (lock остаётся на (tenant, contact, assistant), но привязан семантически к child)
5. Запустить child через FlowEngine с child.session_id

**Routing инвариант:**

Когда incoming message приходит от contact — engine `findActiveSession(tenant, contact, assistant)`:
- Если найдена session со status=`paused_subflow` — найти её child (status IN (active, waiting_input)) — message отправляется child
- Иначе — top-level session

**Завершение child:**

Когда child достигает `end` node — EndNodeHandler выполняет sub-flow specific logic:

1. UPDATE child SET status = `ended`, ended_at = now()
2. Если child.parent_session_id != null:
   - LOAD parent session FOR UPDATE
   - Проверка parent.status == `paused_subflow`. Если нет → log inconsistency, child всё равно ends.
   - UPDATE parent:
     - status = `active`
     - current_node_id = parent_resume_node_id
     - version = version + 1
   - Resume parent через handle:
     - end.status=success → `success`
     - end.status=cancelled → `cancelled`
     - end.status=failed → `failed`

**Timeout handling:**

Scheduled job `flow.subflow.timeout` каждую минуту проверяет sessions со status=`paused_subflow` где `expires_at < now()`:
- Force-end child со status=failed
- Resume parent через `failed` handle

**Validation flow_definition:**

При сохранении flow_definition с subflow nodes — построить call graph:
- Resolve все `flow_id` → их latest definitions
- DFS обнаружение циклов
- Проверка глубины ≤ 3
- Refuse сохранение при нарушении с указанием цикла/глубины

---

### 4.9 rag_query

Запрос к базе знаний через RagAdapterInterface.

**Type:** `rag_query`
**Version:** 1
**Idempotent:** depends on adapter (RAG providers обычно идемпотентны для одинакового prompt)

**Config:**

```json
{
  "knowledge_base_id": "01HQ_kb_main",
  "query": "{{flow.last_user_message}}",
  "options": {
    "min_confidence": "low",
    "max_tokens": 500
  }
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `knowledge_base_id` | string | yes | ULID FK на knowledge_bases |
| `query` | Expression | yes | Resolved prompt |
| `options` | object | no | Adapter-specific опции |

**Output handles:**
- `success` — adapter вернул StructuredRagResult с found=true
- `not_found` — found=false
- `error` — ошибка adapter

**Behavior:**

1. Resolve `query` Expression
2. Загрузить knowledge_base по id, получить provider
3. Получить RagAdapter из registry по provider
4. Вызвать `adapter.query(query, RagQueryContext{tenant, contact, options})`
5. Adapter возвращает StructuredRagResult{found, confidence, answer, intent, metadata}
6. Записать в state.rag.*:
   - `rag.found` = result.found
   - `rag.confidence` = result.confidence
   - `rag.answer` = result.answer
   - `rag.intent` = result.intent
   - `rag.metadata` = result.metadata
7. Переход:
   - `success` если result.found
   - `not_found` если !result.found
   - `error` при exception от adapter

**Validation flow_definition:**
- knowledge_base_id существует и принадлежит этому тенанту
- options валидны для provider данного KB

---

### 4.10 end

Явное завершение flow.

**Type:** `end`
**Version:** 1
**Idempotent:** yes

**Config:**

```json
{
  "status": "success"
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `status` | enum | yes | `success` / `cancelled` / `failed` |

**Output handles:** none (terminal node)

**Behavior:**

1. UPDATE session SET:
   - status = `ended`
   - end_status = config.status
   - ended_at = now()
   - current_node_id = this node id
   - version = version + 1
2. Write `flow_completed` (или `flow_cancelled` / `flow_failed`) event в analytics_events
3. Если session.parent_session_id != null — выполнить subflow resume logic (см. 4.8):
   - Resume parent через handle согласно status
4. Distributed lock освобождается

**Validation flow_definition:**
- Каждый flow_definition должен иметь хотя бы одну `end` node
- end node не должна иметь outgoing edges

---

## 5. Group Storage Model

### 5.1 Concept

Группа — это вложенный объект в `contact.attributes` JSONB или в `flow_sessions.state.flow` JSONB. Не отдельная таблица, не отдельный namespace.

```json
{
  "name": "Иван",
  "form": {
    "input1": "value1",
    "input2": "value2"
  },
  "address": {
    "city": "Москва"
  }
}
```

### 5.2 Group vs leaf

Различение по `jsonb_typeof()`:
- `'object'` → группа
- иначе → leaf

### 5.3 Reserved группы

`contact.meta.*` — owned by webhook ingress. Reserved.

### 5.4 Discovery API (для UI и reports)

Все группы тенанта (для group dropdown в UI):

```sql
SELECT DISTINCT key
FROM contacts c, jsonb_each(c.attributes) AS e(key, value)
WHERE c.tenant_id = ?
  AND jsonb_typeof(value) = 'object'
  AND key NOT IN ('meta');  -- reserved
```

Cache в Redis set `tenant:{id}:contact_groups`, обновляется при save flow_definition (listener на extracted save_to paths).

Поля внутри группы:

```sql
SELECT DISTINCT e.key, jsonb_typeof(e.value) AS type
FROM contacts c, jsonb_each(c.attributes->'<group>') AS e(key, value)
WHERE c.tenant_id = ?
  AND jsonb_typeof(c.attributes->'<group>') = 'object';
```

### 5.5 Reports

Базовые SQL запросы для V1 (без UI builder):

```sql
-- Все ответы группы для CSV экспорта
SELECT
  c.id,
  c.attributes->'<group>'->>'<field1>' AS field1,
  c.attributes->'<group>'->>'<field2>' AS field2
FROM contacts c
WHERE c.tenant_id = ?
  AND jsonb_typeof(c.attributes->'<group>') = 'object';
```

UI report builder — отдельный feature, не V1.

### 5.6 Indexing

V1: общий GIN на `attributes`:

```sql
CREATE INDEX idx_contacts_attributes_gin
ON contacts USING gin (attributes);
```

Точечные partial indexes — добавляются при появлении нагрузки на конкретные группы.

---

## 6. NodeHandlerInterface

### 6.1 Контракт

```php
namespace FAPost\Foundation\Contracts\Flow;

interface NodeHandlerInterface
{
    /**
     * Уникальный тип node в snapshot. 'send_message', 'branch', etc.
     */
    public static function type(): string;

    /**
     * Текущая version handler.
     */
    public static function version(): int;

    /**
     * Версии которые этот handler умеет обрабатывать (для backward-compat).
     * Обычно [self::version()].
     *
     * @return list<int>
     */
    public static function supportedVersions(): array;

    /**
     * @throws NodeExecutionException
     */
    public function execute(NodeExecutionContext $context): NodeExecutionResult;
}
```

### 6.2 NodeExecutionContext

```php
final class NodeExecutionContext
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly FlowSessionInterface $session,
        public readonly array $nodeConfig,
        public readonly StateReader $stateReader,
        public readonly StateWriter $stateWriter,
        public readonly ExpressionEvaluator $evaluator,
    ) {}
}
```

### 6.3 NodeExecutionResult

```php
final class NodeExecutionResult
{
    public function __construct(
        public readonly string $sourceHandle,        // 'success', 'error', etc.
        public readonly array $stateUpdates = [],    // collected updates для batch apply
        public readonly array $errorMeta = [],
    ) {}
}
```

Engine применяет stateUpdates через StateWriter, резолвит next node через edge lookup, идёт дальше.

### 6.4 Registration

Built-in handlers регистрируются в `CoreBootstrap`. Plugin-handlers — через CoreRegistrar:

```php
// в Core registrar
$registry->register(new SendMessageHandler($messageSender));
$registry->register(new InputHandler($validators));
$registry->register(new BranchHandler($evaluator, $stateReader));
$registry->register(new DelayHandler($scheduler));
$registry->register(new AssignHandler($evaluator, $stateWriter));
$registry->register(new CallHandler($transportRegistry, $evaluator, $stateWriter));
$registry->register(new EmitEventHandler($eventBus, $evaluator));
$registry->register(new SubflowHandler($flowDefinitionRepository, $sessionRepository));
$registry->register(new RagQueryHandler($ragRegistry, $knowledgeBaseRepo, $evaluator, $stateWriter));
$registry->register(new EndHandler($sessionRepository, $analyticsEventWriter));
```

---

## 7. Call Transport Layer

### 7.1 CallTransportInterface

```php
namespace FAPost\Foundation\Contracts\Flow\Call;

interface CallTransportInterface
{
    public static function id(): string;
    public static function version(): int;

    /**
     * @throws CallTransportException
     */
    public function execute(CallRequest $request, CallContext $context): CallResult;
}
```

### 7.2 DTO

```php
final class CallRequest
{
    public function __construct(
        public readonly string $target,
        public readonly array $parameters,
        public readonly array $options,
    ) {}
}

final class CallContext
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly FlowSessionInterface $session,
        public readonly string $idempotencyKey,
    ) {}
}

final class CallResult
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $payload,
        public readonly ?string $errorCode,
        public readonly array $metadata,
    ) {}
}
```

### 7.3 Built-in транспорты

**HttpTransport** (id='http', version=1):
- HTTP client (Guzzle / Laravel Http)
- Поддерживает GET/POST/PUT/DELETE/PATCH
- Auth: bearer, basic
- Idempotency-Key header автоматически из CallContext
- Response parsing: JSON if `Content-Type: application/json`, иначе raw text

**HandlerTransport** (id='handler', version=1):
- Dispatch в ActionHandlerRegistry
- target = action ID
- ActionHandlerInterface::handle($parameters, $context) → возврат → CallResult.payload

### 7.4 ActionHandlerInterface

```php
namespace FAPost\Foundation\Contracts\Action;

interface ActionHandlerInterface
{
    public static function id(): string;          // 'crm.sync_contact', namespace 'core.*' reserved
    public static function version(): int;

    /**
     * @throws ActionExecutionException
     */
    public function handle(array $parameters, CallContext $context): mixed;
}
```

ActionHandlerRegistry — отдельный от NodeHandlerRegistry.

---

## 8. Validation flow_definition

При сохранении flow_definition (POST/PUT api) — выполнить валидацию. Failure → 422 с детальным error report.

**Уровни проверок:**

### 8.1 Structural

- Все ссылки на ноды (edges, parent_resume_node) указывают на существующие node ids
- entry_node_id задан явно и существует
- Хотя бы одна `end` node
- Нет orphan nodes (недостижимые от entry)
- Нет dangling edges (edge target не существует)

### 8.2 Type-level (per node)

- Каждый node.type зарегистрирован в NodeHandlerRegistry
- node.version ∈ supportedVersions() для этого type
- node.config соответствует Config schema этого type@version

### 8.3 Reference

- Subflow.flow_id существует (callable flow)
- Subflow call graph: depth ≤ 3, no cycles
- knowledge_base_id в rag_query существует и принадлежит тенанту
- call.transport ∈ зарегистрированные транспорты
- call.transport='handler' → target ∈ зарегистрированные actions

### 8.4 State namespace

- save_to / target в input/assign:
  - Начинается с `contact.` или `flow.`
  - Не нарушает reserved keys
  - Глубина ≤ 1 группы для contact
- Operands в branch — source ∈ {contact, flow, rag, call, module}, path валиден

### 8.5 Variable name uniqueness

Имена переменных уникальны в рамках одного flow_definition (через все Input + Assign):
- Невозможно `Input save_to=contact.name` и далее `Assign target=flow.name` (то же имя в разных storage)
- Это защищает от двусмысленности при чтении в Branch source picker

### 8.6 Edge handles coverage

- Edges из node должны покрывать все handles из NodeHandler::outputHandles()
- Незакрытый handle → warning (не fatal). При runtime выходе через незакрытый handle session попадает в фолбэк logic (engine логирует, помечает sessions failed if no fallback).

---

## 9. Migration & Backward Compat

### 9.1 Версионирование

Каждый node в snapshot содержит `version`. Engine резолвит handler по `(type, version)`:

```php
$handler = $registry->resolve($node['type'], $node['version']);
```

`supportedVersions()` позволяет одному handler обслуживать несколько версий конфига (если реализуется legacy-совместимое чтение). Default — supportedVersions() = [version()].

### 9.2 Breaking changes

При breaking change config:
- Создаётся новый handler с version+1
- Регистрируется параллельно со старым: `registry->register(new SendMessageHandlerV1())` + `registry->register(new SendMessageHandlerV2())`
- Существующие flow_definitions продолжают резолвить v1
- Новые flow_definitions сохраняются с version=2 (UI-builder использует latest)

### 9.3 Deprecated handler removal

Handler можно удалить только когда:
- `flow_active_node_stats` показывает 0 для (type, version)
- Нет `flow_sessions WHERE status IN ('waiting_input', 'paused', 'paused_subflow')` использующих этот type@version

Это `HandlerVersionContract` (Phase 2 plan, task 09).

---

## 10. Acceptance Criteria

V1 считается готовым когда:

- [ ] Все 10 node types реализованы и зарегистрированы в NodeHandlerRegistry
- [ ] CallTransportRegistry содержит http и handler транспорты
- [ ] PHPat правило проверяет реализацию NodeHandlerInterface всеми зарегистрированными
- [ ] Все node types имеют unit-тесты per handler (happy path + edge cases)
- [ ] Validation flow_definition покрывает все 6 уровней (раздел 8)
- [ ] Group storage с GIN index готов к запросам (раздел 5)
- [ ] Subflow lifecycle тесты: success / cancelled / failed / timeout / lock transfer
- [ ] Concurrency тесты: optimistic lock retry, distributed lock contention
- [ ] Idempotency тесты: send_message duplicate prevention, call retry с одинаковым idempotency key
- [ ] State namespace resolver тесты для всех источников (contact / flow / rag / call / module)
- [ ] Integration test: flow с send_message + input + branch + assign + end отрабатывает end-to-end
- [ ] Integration test: flow с subflow вызывает child, parent suspended, child completes, parent resumed
- [ ] Documentation в Notion обновлена

---

## 11. Out of Scope для V1

Следующие capabilities планируются в V1.x или позже:

- ComposeMessage node (объединено в send_message)
- Prompt node (LLM без RAG) — нет реальных кейсов, отложено
- Параметризованный subflow с input/output mapping — V2 после первого продакшена
- Fire-and-forget subflow — заменяется emit_event
- Custom validators в input через code — built-in validators only в V1
- Custom expression functions — стандартный engine without extensions
- attribute_group_definitions metadata table
- UI report builder поверх групп (V1.x)
- Cross-flow group consistency validation (V1.x)
- Group archiving lifecycle
- Glubina вложенности > 1 уровень для contact attribute groups

Каждое — отдельный feature, не требует breaking changes к V1.

---

## 12. Открытые вопросы перед стартом реализации

1. **Expression engine:** Symfony ExpressionLanguage / Twig / custom? Решается отдельным ADR до Sprint 4.
2. **Date input parser:** какая библиотека? Carbon::parse() для basic, что-то более robust для NLP-style ("завтра в 10")?
3. **Action namespace conflict resolution:** при регистрации двух actions с одинаковым id — fail на boot или silently override? Рекомендация: fail.
4. **Subflow timeout job interval:** каждую минуту достаточно? Или event-driven через scheduler?
5. **GIN index strategy:** общий на attributes или partial по конкретным частым группам — определять по boot data.

---

## 13. Связанные документы

- `Platform Architecture v2.2` — раздел 3.4 Flow Engine, раздел 5 Concurrency
- `FAPost Plan v2.0` — Phase 2 tasks 09-16
- `ADR-05 Foundation Package` — где живут foundation contracts
- `ADR Handler Versioning Contract` — task 09
- `ADR Subflow Composition` — отдельный документ (TBD)
- `ADR Expression Language` — отдельный документ (TBD)

---

**Документ финальный для V1. Готов к передаче в реализацию.**

---

## Связано с

- [[README]] — flow engine README
- [[12-brownfield-audit]] — аудит после snapshot
- [[13-synthesis]] — синтез
