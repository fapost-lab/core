# Node · `call`

Вызов внешнего или внутреннего сервиса. Объединяет Integration (HTTP) и Action (in-process).

**Type:** `call`
**Version:** 1
**Idempotent:** yes (через `CallContext.idempotencyKey`)

## Config

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
    "success_when": "2xx",
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
| `transport` | string | yes | ID транспорта в `CallTransportRegistry`. Built-in: `http`, `handler`. **Literal**, не Expression. |
| `target` | Expression | yes | Семантика target зависит от транспорта |
| `parameters` | object | no | Map: `<key> → Expression`. Resolved при выполнении |
| `transport_options` | object | no | Transport-specific опции (raw passthrough) |
| `result_mapping` | object | no | Map: `target_path → result_path`. Куда положить результат в state |

## Семантика target по транспорту

| Transport | target |
|-----------|--------|
| `http` | `<METHOD> <URL>`, например `POST https://api.example.com/users` |
| `handler` | ID action handler в `ActionHandlerRegistry`, например `crm.sync_contact` |

## Output handles

- `success` — вызов выполнен успешно (по `success_when` policy)
- `error` — ошибка транспорта или action handler

## success_when policy (HTTP transport)

> **Patch v1.1:** новая опция в `transport_options`.

`success_when` определяет, какой HTTP response код считается success:

| Value | Поведение |
|-------|-----------|
| `"2xx"` (default) | 200-299 → `success`. Остальное → `error` |
| `"any_response"` | Любой HTTP response (включая 4xx, 5xx) → `success`. Только transport failure → `error` |
| `"2xx_or_4xx"` | 2xx и 4xx → `success` (для API где 4xx — бизнес-смысл). 5xx → `error` |

> **Уточнение для ADR Idempotency Strategy:** обобщить до generic формы вроде `success_statuses: [200, 201, 404]` или `success_status_classes: ["2xx"]` — см. [09-out-of-scope-and-open-questions.md](../09-out-of-scope-and-open-questions.md), раздел 9.3.

### Failure boundary

| Тип | Handle |
|-----|--------|
| Transport-level failure (DNS, connection refused, timeout, TLS) | **`error` всегда** |
| HTTP response получен (любой код) | по `success_when` policy |

`CallResult.metadata.status_code` всегда содержит фактический HTTP код (для всех режимов). При `success_when="any_response"` с non-2xx — `CallResult.error_code` = `"http_4xx"` или `"http_5xx"` (для downstream branch).

### Аналог для других транспортов

`handler` transport: ActionHandler возвращает результат → `success`. ActionHandler бросает exception → `error`. Без конфигурации — действия идемпотентны и предсказуемы.

Custom transports могут вводить свои `success_when`-like опции через `transport_options` (transport-specific passthrough).

## Behavior

1. Resolve `transport` (literal, не Expression — выбор транспорта runtime запрещён)
2. Получить транспорт из `CallTransportRegistry`. Если нет — session failed
3. Resolve `target` через Expression
4. Resolve `parameters` (recursively, каждое значение)
5. Построить `CallRequest{target, parameters, options=transport_options}`
6. Построить `CallContext{tenant, session, idempotencyKey = "{session.id}:{node.id}:{attempt_number}"}`. В V1 `attempt_number = 1` (статически, без автоинкремента — см. ADR Message Routing & Concurrency Control). Идемпотентность вызова обеспечивается distributed lock на сессии; Redis-based dedup outbound — V1.x.
7. Вызвать `transport.execute(request, context)` — возвращает `CallResult`
8. Apply success policy:
   - HTTP transport: matches `success_when` → `result.success = true`
   - Handler transport: returned without exception → `result.success = true`
9. Если `result.success && result_mapping задан` — apply mapping:
   - Для каждой пары `target_path → result_path`:
     - Извлечь значение из CallResult по `result_path` (например, `payload.data.id` → `result.payload['data']['id']`)
     - Если path не существует → записать null + warning в logs (не fail). См. ниже.
     - Записать в state:
       - `flow.*` / `call.*` / `rag.*` target → попадает в `result.stateChanges`
       - `contact.*` target → handler вызывает `$context->contactWriter->write(...)` immediate
10. Сохранить session с version bump
11. Переход:
    - `success` если `result.success`
    - `error` иначе (включая исключения транспорта)

### result_mapping с missing path

> **Patch v1.1:** missing path → null + warning, не fail.

API responses часто варьируются (пустой `data`, optional fields). Hard fail на missing path делает flows fragile. Null в state — обрабатываемо в downstream branch через `is_null` operator.

```json
{
  "result_mapping": {
    "flow.user_id": "payload.data.id"
  }
}
```

Если `payload = {error: "not found"}`:
- `flow.user_id = null`
- `flow_logs` warning: `"Path 'payload.data.id' not found in CallResult, mapped to null"`
- Continue execution через `success` handle (если HTTP 2xx)

Downstream pattern:

```
call → success
  ↓
branch:
  flow.user_id is_null → handle "user_not_created" → error path
  default → continue
```

`strict_mapping: true` (hard fail при missing path) — V1.x, см. [09-out-of-scope-and-open-questions.md](../09-out-of-scope-and-open-questions.md).

## Built-in транспорты

См. [04-call-transport-layer.md](../04-call-transport-layer.md), раздел 4.3.

`http`:
- Methods: GET, POST, PUT, DELETE, PATCH
- parameters keys: `body.*`, `query.*`, `headers.*`, `auth.bearer`, `auth.basic.username`, `auth.basic.password`
- Response в `CallResult.payload` (parsed JSON если `Content-Type=application/json`, иначе raw string)
- Idempotency key пишется в header `Idempotency-Key`

`handler`:
- target = action ID
- parameters передаются как массив в `ActionHandlerInterface::handle($parameters, $context)`
- Возврат action handler → `CallResult.payload`
- Idempotency key передаётся через `CallContext`

## Validation flow_definition

- `transport` ∈ ids зарегистрированных транспортов
- `target` формат соответствует транспорту (для http — VALID METHOD + URL pattern)
- `result_mapping` `target_path` начинается с `flow.` или `contact.`
- `success_when` (если задан) ∈ `{"2xx", "any_response", "2xx_or_4xx"}`

---

## Связано с

- [[README]] — nodes README
- [[04-call-transport-layer]] — transport layer для call ноды
- [[10-state-writer-semantics]] — семантика state writer
- [[12-solutions-modules]] — Solutions регистрируют handlers
