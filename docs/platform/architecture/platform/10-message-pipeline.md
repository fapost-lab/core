# 10 — Message Pipeline

## Входящий путь

| Шаг | Действие |
| --- | --- |
| 1. `POST /webhook/{channel}/{hash}` | Router резолвит channel adapter — без БД |
| 2. Signature verify | ChannelAdapter — без БД |
| 3. Idempotency check | Redis SET NX — дроп дублей |
| 4. Tenant resolve | Redis lookup: `public_hash → {tenant_id, bot_id}` |
| 5. Dispatch + 200 | `IncomingMessageJob` в очередь, немедленный 200 |
| 6. Worker: lock | Distributed lock |
| 7. Worker: flow | Contact findOrCreate → FlowSession → resume()/start() |
| 8. Worker: send | MessageSender → transactional queue |
| 9. Worker: persist | FlowSession save с version bump, flow_logs |

> ⚠ Шаги 1–3 без БД. Шаг 4 только Redis. Landlord = control plane, не hot path.
> 

---

## Изоляция очередей

| Очередь | Приоритет | Описание |
| --- | --- | --- |
| `messaging.transactional` | HIGH | Ответы в диалоге |
| `messaging.broadcast` | LOW | Рассылки |
| `messaging.system` | — | Служебные уведомления |
| `flow.execution` | — | Обработка входящих |
| `sync.external` | — | Синхронизации |
| `scheduled.triggers` | — | Крон-запуски flow |

> ✕ Transactional и broadcast через одну очередь — классический queue starvation.
> 

---

## Rate limiting и backpressure

- Provider rate limit: Redis per `(bot_id, chat_id)` — превентивно
- `BroadcastSendJob` проверяет длину transactional queue, release с delay при насыщении

---

## Связано с

- [[01-octane-ingress-only]] — ADR-01 (отменён): stateless webhook ingress
- [[01-webhook-pipeline]] — диаграмма webhook pipeline
- [[09-message-routing-concurrency]] — ADR concurrency
- [[conversation-logging]] — логирование диалогов
- [[04-assistant-domain]] — Assistant Domain
- [[diagrams/01-webhook-pipeline]] — диаграмма webhook pipeline