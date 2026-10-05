# 08 — Concurrency and Idempotency

## Problem

Two workers can pick up the same session at once: two messages in a row, a Telegram retry, a parallel delayed node.
Without protection that means a double transition through the graph, a double send and lost state.

---

## Layers of protection

| Layer | Mechanism |
| --- | --- |
| **1. Ingress idempotency** | `WebhookController` does Redis `SET NX "processed:{idempotency_key}"` with a 24h TTL. A duplicate delivery is dropped before it reaches the queue. |
| **2. Distributed lock** | Redis lock `session_lock:{tenant}:{contact}:{assistant}` (`LockScope`), TTL 30s, kept alive by a heartbeat. `MessageRouter` acquires it through `LockAcquisitionPolicy`: 3 attempts, 2s apart. |
| **3. Optimistic locking** | `flow_sessions.version`. `saveWithOptimisticLock()` runs `UPDATE ... WHERE version = N`; 0 rows raises `OptimisticLockConflictException`, and `FlowOrchestrator` makes 3 attempts in total with a growing delay between them. |
| **4. Outbound idempotency** | `MessageSender` reserves Redis `msg:sent:{idempotency_key}` (`SET NX`, 24h) before delivery and releases it if delivery fails, so a retried job never sends the same message twice. |

### When the lock is not acquired

- After 3 failed attempts in `MessageRouter`, `DropPolicy::applyBusy()` sends the contact a busy notice (the assistant's
  `busy_message`, else the `errors.busy` catalog string) and **the message is dropped** (`lock_timeout`). The inbound
  message is still in the conversation transcript because it is captured before routing.
- A lock timeout inside the engine (`engine_lock_timeout`) is different: `IncomingMessageJob` releases the job back to the
  queue with a delay (1s, 2s, 5s, then 10s) and retries, up to `tries = 5`.
- A lost lock (the heartbeat found the claim taken over) abandons the run without a retry; the new owner processes the
  contact.

---

## Node handler idempotency

Every handler must be safe to retry. The execution context carries an `idempotencyKey` derived from the inbound update.

- `send_message` — passes the key to the sender, so `MessageSender` drops a repeat delivery.
- `call` — the HTTP transport sets the `Idempotency-Key` header from `$context->idempotencyKey` unless the config
  sets one.
- `set_tag`, `rag_query` — idempotent by nature.
- `assign` — idempotent for scalar targets (assigning the same value again changes nothing), but **not** for array
  targets: a session array variable appends, so a re-run appends the value again.

---

## Related

- ADR 09 (message routing and concurrency)
- session state machine and webhook pipeline diagrams in `docs/reference/diagrams/`
- ADR 01 (cancelled): stateless ingress
