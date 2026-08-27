# Node · `end`

Явное завершение flow.

**Type:** `end`
**Version:** 1
**Idempotent:** yes

## Config

```json
{
  "status": "success"
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `status` | enum | yes | `success` / `cancelled` / `failed` |

## Output handles

None (terminal node).

## Behavior

1. UPDATE session SET:
   - `status` = `ended`
   - `end_status` = `config.status`
   - `ended_at` = `now()`
   - `current_node_id` = this node id
   - `version` = `version + 1`
2. Write `flow_completed` (или `flow_cancelled` / `flow_failed`) event в `analytics_events`
3. Если `session.parent_session_id != null` — выполнить subflow resume logic (см. [08-subflow.md](08-subflow.md), раздел "Завершение child"):
   - Resume parent через handle согласно status (`success` / `cancelled` / `failed`)
4. Distributed lock освобождается

## Validation flow_definition

- Каждый flow_definition должен иметь хотя бы одну `end` node
- end node не должна иметь outgoing edges

---

## Связано с

- [[README]] — nodes README
- [[04-session-state-machine]] — end нода завершает state machine
- [[11-end-node-completion]] — builder specs для end ноды
