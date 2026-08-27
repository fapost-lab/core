# Node · `emit_event`

Async генерация события для запуска других flow через triggers.

**Type:** `emit_event`
**Version:** 1
**Idempotent:** yes (event publishing — append-only)

## Config

```json
{
  "event_type": "sales.order.created",
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

## Output handles

- `success`

## Behavior

1. Resolve payload через Expressions
2. Publish event через FlowTriggerEventBus:
   - `tenant_id` (из session)
   - `event_type`
   - `payload` (resolved)
   - `source: {flow_id, session_id, node_id, assistant_id}`
3. Engine продолжает с `success` handle. **Не ждёт** обработки события.
4. Triggers с `type=event, event_type=<this>` запустят свои flow асинхронно (в `scheduled.triggers` queue)

## Event scope

> **Patch v1.1:** новый sub-block.

Events scoped to **tenant**. Все triggers подписанные на `event_type` получают event независимо от source assistant. `source.assistant_id` присутствует в metadata, но не используется как filter в V1.

### Naming convention для cross-assistant изоляции

Рекомендуемая convention в документации:

```
sales.order.created
support.ticket.opened
hr.employee.onboarded
```

Namespace по domain/assistant prefix. **Не enforced**, но рекомендовано.

### V1.x: explicit filter

Можно будет добавить `assistant_filter` в trigger config:

```json
{
  "trigger_type": "event",
  "event_type": "order.created",
  "filter": {
    "source_assistant_id": "01HQ_sales"
  }
}
```

Не V1. Naming convention достаточно для первой итерации.

## Validation flow_definition

- `event_type` non-empty, alphanumeric + dot

---

## Связано с

- [[README]] — nodes README
- [[08-subflow]] — альтернатива go_to_flow через emit-event
- [[README]] — flow engine README (specs/flow-engine)
