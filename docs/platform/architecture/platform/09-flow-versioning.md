# 09 — Версионирование Flow

> Содержимое зафиксировано на основе platform-architecture-v2. Март 2026.
> 

---

## Модель версионирования

| Компонент | Описание |
| --- | --- |
| **flow_definitions** | Каждое сохранение = новая запись. Полный JSON snapshot нод. `version` auto-increment per logical `flow_id`. |
| **flow_sessions** | Фиксирует `flow_definition_id`  • `flow_version` на старте. Выполняется по своему snapshot до завершения. Никогда не мигрирует автоматически. |
| **Node version field** | `{ "type": "condition", "version": 2, "config": {} }`. Engine резолвит handler по `(type, version)` из in-memory registry. |
| **NodeHandlerRegistry** | In-memory, собирается при boot из ServiceProvider каждого модуля. Код — source of truth. БД хранит только observability stats. |

---

## Правила изменений

- **Backward-compatible изменение** (новое поле с default) → `version` не меняется
- **Breaking change** → `version++` в новых flow, старый handler остаётся зарегистрированным
- **Deprecated handler** удалять только когда `flow_active_node_stats` показывает 0 активных сессий И нет `flow_sessions` со статусом `waiting/paused` с этим `type@version`
- `flow_definitions` хранит последние N версий per flow (configurable, default: 50)

---

## flow_active_node_stats

Read-only агрегат: `(type, version)` нод в живых сессиях. Только для observability — когда можно безопасно удалить deprecated handler.

---

## Split authority — запрещён

Registry живёт в коде, не в таблице. БД хранит только observability stats. Добавление `flow_node_handlers` как DB-таблицы создаёт split authority между кодом и базой данных — это архитектурный антипаттерн.

---

## Сессия и snapshot

Сессия выполняется по своему `flow_definition_id` snapshot до завершения — никогда не мигрирует на новую версию flow автоматически. Это гарантирует детерминизм: пользователь в середине сценария не получит неожиданное поведение из-за обновления flow.

---

## Связано с

- [[07-versioning]] — спека версионирования flow engine
- [[06-flow-engine]] — flow engine архитектура
- [[03-node-handler-interface]] — Handler Version Contract
- [[specs/flow-engine/07-versioning]] — спека версионирования
- [[specs/flow-engine/README]] — обзор flow engine спеки