# 08 — Конкурентность и идемпотентность

## Проблема

Два воркера могут одновременно взять одну сессию при: двух сообщениях подряд, Telegram retry, параллельном delayed node. Без защиты: двойной переход по графу, двойная отправка, потеря state.

---

## Три уровня защиты

| Уровень | Механизм |
| --- | --- |
| **1. Idempotency key** | Redis `SET NX "processed:{update_id}"` TTL=24h. Дроп дублей до входа в очередь |
| **2. Distributed lock** | Redis lock `"session_lock:{tenant}:{contact}:{assistant}"` TTL=30s. Не получил → backoff, не дроп |
| **3. Optimistic locking** | `flow_sessions.version`. `UPDATE ... WHERE version = N`. 0 rows → retry |

---

## Node handler idempotency

Каждый handler обязан быть safe to retry:

- `send_message` — хранить `sent_message_ids`, не отправлять повторно
- `webhook` — передавать idempotency key во внешний запрос
- `set_attribute`, `rag_query` — idempotent by nature

---

## Связано с

- [[09-message-routing-concurrency]] — ADR по маршрутизации
- [[04-session-state-machine]] — state machine сессии
- [[01-webhook-pipeline]] — webhook pipeline
- [[01-octane-ingress-only]] — ADR Octane ingress
- [[specs/flow-engine/README]] — обзор flow engine