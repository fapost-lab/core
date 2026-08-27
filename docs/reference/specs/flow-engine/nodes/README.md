# Flow Engine — Per-node specs

| Файл | Нода | Статус |
|------|------|--------|
| [01-send-message](01-send-message.md) | `send_message` | ✅ |
| [02-input](02-input.md) | `input` | ✅ |
| [03-branch](03-branch.md) | `condition` / `branch` | ✅ |
| [04-delay](04-delay.md) | `delay` | ✅ |
| [05-assign](05-assign.md) | `assign` | ✅ |
| [06-call](06-call.md) | `call` | ✅ |
| [07-emit-event](07-emit-event.md) | `emit_event` | ✅ |
| [08-subflow](08-subflow.md) | `subflow` | ✅ |
| [09-rag-query](09-rag-query.md) | `rag_query` | ✅ |
| [10-end](10-end.md) | `end` | ✅ |
| [11-loop](11-loop.md) | `loop` + `loop_end` | 🚧 частично |
| [set-tag](set-tag.md) | `set_tag` | ✅ |
| [notify](notify.md) | `notify` | ✅ |
| [auth-request](auth-request.md) | `auth_request` | ✅ |

---

## Связано с

- [[README]] — обзор всего flow engine (specs/flow-engine/README.md)
- [[../../../plans/flow-engine/implementation-plan]] — план реализации нод
- [[03-node-handler-interface]] — интерфейс node handler
- [[06-flow-engine]] — flow engine архитектура
- [[TASKS]] — задачи реализации
