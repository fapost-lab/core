# Диаграммы FAPost Core

Визуальное описание ключевых флоу платформы. Все диаграммы — Mermaid, рендерятся в Obsidian нативно.

| Диаграмма | Что описывает |
|-----------|---------------|
| [[01-webhook-pipeline]] | Входящее сообщение → очередь → обработка → ответ |
| [[02-flow-engine-loop]] | Цикл выполнения flow engine (нода за нодой) |
| [[03-tenant-context]] | Установка tenant-контекста и переключение схемы |
| [[04-session-state-machine]] | Жизненный цикл flow session (статусы) |
| [[05-conversation-logging]] | Логирование диалогов (новый домен) |
| [[06-subflow-lifecycle]] | Запуск/возврат subflow |

> Добавляй новые диаграммы сюда и в [[../INDEX]].

---

## Связано с

- [[01-webhook-pipeline]] — диаграмма webhook pipeline
- [[02-flow-engine-loop]] — диаграмма execution loop
- [[03-tenant-context]] — диаграмма tenant context
- [[04-session-state-machine]] — диаграмма state machine
- [[05-conversation-logging]] — диаграмма conversation logging
- [[06-subflow-lifecycle]] — диаграмма lifecycle subflow
