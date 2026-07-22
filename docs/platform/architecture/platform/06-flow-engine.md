# 06 — Flow Engine

## Описание

Flow — граф нод хранящийся как JSON в БД. Engine детерминирован: condition-нода читает конкретные значения из state и делает предсказуемый переход.

---

## Схема

| Компонент | Описание |
| --- | --- |
| **flow_definitions** | id, tenant_id, flow_id (логический UUID), version (int), name, nodes (JSON snapshot), is_active |
| **flow_sessions** | id, tenant_id, contact_id, flow_id, flow_definition_id (FK на snapshot), current_node_id, state (JSON namespaced), status, version (optimistic lock), expires_at |
| **flow_triggers** | Тип: message / schedule / webhook / api / event |
| **flow_active_node_stats** | Read-only агрегат: (type, version) нод в живых сессиях |

---

## Namespaced State

| Namespace | Владелец | Описание |
| --- | --- | --- |
| `system.*` | Engine | started_at, current_node, retry_count |
| `flow.*` | input/set_attribute | Данные диалога |
| `rag.*` | rag_query нода | Результат RAG, умирает с сессией |
| `module.*` | Модуль через DataAccessor | `module.hr.*`, `module.recruitment.*` |

> ✕ Condition нода НЕ читает напрямую из модульных таблиц. Только через `DataAccessorInterface`.
> 

---

## Встроенные типы нод

- `send_message` — текст, фото, документ, inline-клавиатура
- `input` — ожидать ответа, сохранить в `flow.*`
- `condition` — ветвление по значениям из state
- `delay` — пауза
- `webhook` — вызов внешнего URL
- `set_attribute` — в `contacts.attributes` или `flow.*`
- `rag_query` — запрос к базе знаний
- `emit_event` — запуск цепочки flow

---

## NodeHandlerRegistry

In-memory, собирается при boot из ServiceProvider каждого модуля. Код — source of truth. БД хранит только observability stats.

> ✕ `flow_node_handlers` как таблица БД — запрещено. Создаёт split authority между кодом и БД.
>

---

## Связано с

- [[README]] — спека flow engine
- [[02-flow-engine-loop]] — диаграмма execution loop
- [[03-node-handler-interface]] — интерфейс handler
- [[01-state-model]] — state model
- [[09-message-routing-concurrency]] — ADR concurrency
- [[10-state-writer-semantics]] — ADR state writer semantics
- [[diagrams/04-session-state-machine]] — диаграмма state machine