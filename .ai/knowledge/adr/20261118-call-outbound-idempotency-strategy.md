---
id: adr-20261118-call-outbound-idempotency-strategy
type: adr
status: accepted
date: 2026-11-18
domains:
  - flow
paths:
  - "app/Domains/Flow/Call/**"
  - app/Domains/Flow/Handlers/CallNodeHandler.php
summary: The call node mints the idempotency key and attempt number and keeps a Redis record of completed calls (replayed instead of re-sent) through a ledger service; the transports only forward them as headers
---
# The call node owns the outbound idempotency key, the attempt number and the Redis dedup record

This is the "Idempotency Strategy" that ADR-09 left as a V1.x item.

## Context

A `call` node runs on a queue. A step is run again when the worker dies after the request went out but before
the session was saved, or when the session save loses an optimistic-lock race. Before this decision the second
run sent the request again and relied on the receiver honouring the `Idempotency-Key` header: a receiver that
does not honour it creates a second record for one session. The next roadmap item, `call` retry with backoff,
makes this worse on purpose, because it sends the same call several times by design.

The key itself already existed: the engine's execution key plus `:` and the node id (new on every loop pass,
the same on a queue retry of the step). What was missing was an attempt counter, a record that a call
completed, and an answer to where they live. `CallContext` belongs to Foundation, a separate repository, so a
new field there needs a release.

## Decision

1. **The handler owns it.** `CallNodeHandler` mints the key (unchanged), asks `CallExecutionLedger` for the
   attempt number and for an earlier outcome, and only then calls the transport. Transports stay free of
   state: `HttpTransport` forwards the key as `Idempotency-Key` and the attempt number as
   `X-FaPost-Attempt-Number` (a header the caller sets itself wins, as for the key). The attempt number
   travels in `CallRequest::$options['attempt_number']`, a key the handler always overwrites, so a flow
   author cannot set it. The `handler` transport (an in-process action) cannot receive it until Foundation's
   `CallContext` gains a field; the key is still in `CallContext`.
2. **The record replays the outcome.** After a call returns `success`, the ledger stores the whole
   `CallResult` (success, payload, error code, metadata) under `flow:call:{tenant}:{key}:done`. A repeated
   execution with the same key finds it, skips the transport and continues with the stored result, so
   `save_to_variable`, `result_mapping` and the handle behave as on the first run. The node's metadata then
   carries `deduplicated: true`.
3. **Only a success is recorded.** A failed call stays retryable: the retry task needs to send it again, and
   the receiver still sees the same key. An execution that died in flight leaves only the attempt counter
   (`flow:call:{tenant}:{key}:attempts`), so the next run sends again with attempt + 1 and the same key.
4. **A result over 256 KiB is not recorded.** The dedup is lost for that one call (logged as a warning), the
   remote header still protects it.
5. **Redis trouble never blocks a call.** A failed read, increment or write is logged and the call is sent as
   before this decision (at-least-once with the header).
6. **The window is a platform setting.** `flow.call_dedup_ttl_hours`, default 192 (8 days), minimum 168
   (7 days), maximum 720. The floor is the Horizon failed-job retention (`horizon.trim.failed`, 7 days) plus
   a day of grace: a failed step can be retried by hand up to then, and its call must still be recognised.
   The `.env` value `FLOW_CALL_DEDUP_TTL_HOURS` is only the installation's default (`conventions/runtime-settings`).
7. **A replay is outside the call quota.** The `call_executions` unit was spent by the first run, so a replayed
   execution neither consumes a unit nor can be refused with `limit_reached` (for example after the period rolled
   over): the node checks the ledger before the quota.
8. **Test calls are outside it.** `CallTester` (the builder's "test call") builds its own synthetic context and
   never uses the ledger: pressing the button twice must send twice.

## Alternatives

- **Mint the key and keep the record in `HttpTransport`.** Rejected: the retry loop of the next task is node
  config and must work for every transport, so the counter has to live where the loop lives. A transport
  keeping Redis state would also have to be repeated by every Solution transport, and the `handler` transport
  would get none of it.
- **Add `attemptNumber` to Foundation's `CallContext`.** Deferred, not rejected: it needs a Foundation
  release and a coordinated Core bump. The options key is the smaller step and is replaced by the field when
  Foundation gets it.
- **A marker only ("this was sent"), no stored outcome.** Rejected: the replayed step still runs response
  handling, so without the outcome the variables stay empty and the handle is arbitrary.
- **The application cache store instead of Redis.** Rejected: `config/cache.php` defaults to `database`, which
  would quietly change the contract the roadmap and every sibling (`MessageSender`, the `processed:` keys, the
  session lock) already rely on.
- **Reserve with `SET NX` before sending (as `MessageSender` does).** Rejected: the session lock already
  serialises runs of one session, and a reservation left by a crashed worker would block the retry that exists
  to recover from the crash.
- **A TTL in `config/flow.php` only.** Rejected: an operator tuning the window is the case
  `conventions/runtime-settings` describes; the field's minimum keeps it above the retention.

## Consequences

- A retried step no longer repeats a call that already succeeded, whatever the receiver does with the header.
- Response bodies (and response headers) of successful calls sit in Redis for the window, under a key with the
  tenant id. Request headers and parameters, which carry credentials, are never stored.
- The retry task builds on `attempt_number` and the ledger instead of adding its own counter.
- Three docs change with it: `nodes/06-call.md`, `04-call-transport-layer.md`, `08-concurrency-idempotency.md`.
- Foundation's `CallContext` and `ActionHandlerInterface` stay unchanged; moving the attempt number into
  `CallContext` is a later, separate change.
