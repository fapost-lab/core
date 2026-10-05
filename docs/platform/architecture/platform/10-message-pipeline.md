# 10 — Message Pipeline

## Inbound path

| Step | Action |
| --- | --- |
| 1. `POST /webhook/{channel}/{hash}` | `WebhookController` resolves the registry entry by hash: Redis first, landlord `webhook_registry` as a fallback |
| 2. Signature verify | Channel adapter (declarative ingress spec when published, adapter code otherwise), secret taken from the registry entry |
| 3. Idempotency check | Redis `SET NX processed:{key}` with a 24h TTL; duplicates get 200 and are dropped |
| 4. Routing envelope | The registry entry gives `{tenant_id, assistant_id, channel_id}` plus the tenant `schema`, platform and secret token |
| 5. Dispatch + 200 | `IncomingMessageJob` is queued on `flow.execution`; the response is an immediate `{"ok": true}` |
| 6. Worker: setup | Switch to the tenant schema, normalize the payload through the channel adapter, find or create the contact, capture the inbound message into the conversation transcript |
| 7. Worker: routing | `MessageRouter`: built-in commands, typing indicator, session lock, staff-ownership check, session state classification |
| 8. Worker: flow | `FlowOrchestrator` → `FlowEngine`: resume the waiting session or start via a trigger |
| 9. Worker: send | `FlowMessageSender` → `MessageSender` (idempotency, rate limit, delivery) inline in the worker |
| 10. Worker: persist | `FlowSession` save with a version bump (optimistic lock), `flow_logs` |

> Steps 1 to 5 never touch the database on the hot path: the Redis registry answers, and the landlord table is only a
> fallback on a Redis miss. Landlord is a control plane, not a hot path.

Failure behavior of step 7 (lock miss, busy notice) is described in
[08-concurrency-idempotency](08-concurrency-idempotency.md). A lock miss is dropped, not retried; only an engine
lock timeout (`engine_lock_timeout`) is retried by `IncomingMessageJob`.

---

## Queue isolation

| Queue | Used by |
| --- | --- |
| `flow.execution` | `IncomingMessageJob`, `ResumeDelayedFlowSessionJob`, `ResumeTimedOutSendMessageNodeJob` |
| `messaging.transactional` | `SendTransactionalMessageJob`; supervised together with `flow.execution` |
| `messaging.broadcast` | `RunBroadcastJob`, `SendBroadcastRecipientJob`, `BroadcastSendJob` (also used by contact notifications) |
| `messaging.system` | Staff notifications, channel webhook sync, media cleanup |
| `messaging.logging` | Conversation transcript jobs (`PersistConversationMessageJob`, `UpdateConversationDeliveryStatusJob`, `FetchConversationMediaJob`) |
| `scheduled.triggers` | Event-chain jobs (`DispatchFlowTriggerEventJob`, `StartFlowFromEventJob`) |
| `sync.external` | Reserved in the Horizon config; nothing dispatches to it today (no producer) |

Horizon groups: `flow.execution` + `messaging.transactional` (high priority), `sync.external` + `scheduled.triggers`,
and `messaging.broadcast` + `messaging.system` + `messaging.logging` (low priority).

> Never route transactional and broadcast traffic through one queue: that is classic queue starvation.

---

## Rate limiting and backpressure

- Provider rate limit: `MessageSender` keeps a Redis counter per `(channel_id, chat_id)` (`rate:{channel}:{chat}`, 60s
  window, limit from `messaging.rate_limit_per_minute`, default 30). Exceeding it throws `RateLimitExceededException`
  and releases the idempotency reservation so the job can retry.
- Broadcast backpressure lives in `RunBroadcastJob`, not in `BroadcastSendJob`. When the tenant setting
  `broadcast_backpressure` is on and the `messaging.broadcast` queue holds more than 1000 jobs, the run is re-dispatched
  with a 30s delay before any recipient rows are created. Otherwise recipients are fanned out in per-second chunks
  (`broadcast_chunk_size`).

---

## Related

- ADR 01 (cancelled): stateless webhook ingress
- ADR 09: message routing and concurrency
- [04-assistant-domain](04-assistant-domain.md) — Assistant Domain
- webhook pipeline diagram: `docs/reference/diagrams/01-webhook-pipeline.md`
- conversation logging spec: `docs/reference/specs/messaging/conversation-logging.md`
