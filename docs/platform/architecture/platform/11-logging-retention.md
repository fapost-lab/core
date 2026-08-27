# 10 — Логирование и retention

## Два уровня хранения

| Уровень | Описание |
| --- | --- |
| **flow_logs (raw)** | Каждое выполнение ноды. Retention 30 дней. Monthly partitioning — DROP partition = мгновенно |
| **analytics_events (aggregated)** | Бизнес-события: flow_started, flow_completed, broadcast_sent. Навсегда |

---

## Что НЕ логировать в raw

- delay node executions (их миллионы при рассылке)
- Промежуточные state updates внутри одной сессии
- Успешные idempotency check hits

> ⚠ Партиционирование обязательно с первого дня. DELETE по retention на тяжёлой таблице убивает PostgreSQL.
>

---

## Связано с

- [[conversation-logging]] — архитектура хранения диалогов
- [[05-conversation-logging]] — диаграмма conversation logging
- [[06-flow-engine]] — flow engine генерирует flow_logs
- [[specs/messaging/conversation-logging]] — спека conversation logging