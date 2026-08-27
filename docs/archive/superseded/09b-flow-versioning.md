# 09b — Версионирование Flow

> Страница-placeholder. Содержимое перенести из архитектурного документа (platform-architecture-v2, раздел 9).
> 

---

## Что здесь должно быть

Раздел описывает:

- `flow_definitions` — каждое сохранение = новая запись, полный JSON snapshot
- `flow_sessions` — фиксирует `flow_definition_id` + `flow_version` на старте, выполняется до завершения без автомиграции
- Node version field: `{ "type": "condition", "version": 2, "config": {} }`
- `NodeHandlerRegistry` — in-memory, собирается при boot, код — source of truth
- Правило backward-compatible vs breaking change
- Правило безопасного удаления handler: `flow_active_node_stats` = 0 AND нет `waiting/paused` сессий с этим `type@version`

> ⚠ Детальная реализация — задача 09 (Flow definition & registry).
>