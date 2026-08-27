# Node · `delay`

Пауза перед переходом к следующей ноде.

**Type:** `delay`
**Version:** 1
**Idempotent:** yes

## Config

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

## Output handles

- `success` — после паузы

## Behavior

1. Resolve target time:
   - relative: `now() + parsed duration`
   - absolute: parse Expression as datetime
2. Schedule delayed job в `flow.execution` queue с `delay = target - now()`
3. Сохраняет session в статусе `paused`, `expires_at` обновляется на target если оно позже текущего
4. Когда delayed job выполняется — проверяет `current_node_id == this_node_id`. Если другая нода — return (concurrency safety, см. раздел 5.3 архитектуры)
5. Resume через `success` handle

## Validation flow_definition

- Ровно одно из `value` / `absolute_at` задано
- ISO 8601 формат корректен

## Logging

`delay` execution **не логируется** в `flow_logs` (раздел 16 plan v2 — что не логировать).

---

## Связано с

- [[README]] — nodes README
- [[01-state-model]] — delay хранит scheduled_at в state
- [[08-concurrency-idempotency]] — idempotency при delay resume
