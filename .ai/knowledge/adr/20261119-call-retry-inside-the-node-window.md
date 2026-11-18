---
id: adr-20261119-call-retry-inside-the-node-window
type: adr
status: proposed
date: 2026-11-19
domains:
  - flow
paths:
  - app/Domains/Flow/Call/CallRetryPolicy.php
  - app/Domains/Flow/Handlers/CallNodeHandler.php
summary: Why a call node retries in process inside an 18 s window from the first send (lock TTL between nodes, segment budget's slowest node) instead of parking the session for long backoff
reviewed_at: 2026-11-19
---
# A `call` retries in process, inside an 18-second node window, never by parking the session

## Context

Spec `flow-engine-v1x` (phase 2) asks for `call` retry with backoff: a flaky endpoint is sent the call again before
the node takes `error`, without duplicating its effect remotely. The outbound ledger
(`adr-20261118-call-outbound-idempotency-strategy`) already gives every send of one execution the same key and a rising
attempt number, and records only a success. The open question was where the wait between sends happens.

Three limits bind a wait inside the step:

- The session lock (TTL 30 s) is extended by the engine **between nodes**, not on a timer. A node running past it lets
  another worker take the session and run the step again — the duplicate this feature exists to avoid.
- An automation segment (`adr-20261118-segmented-automation-runs`) is sized as at most 30 s of segment plus the slowest
  node, about 18 s (the 8 s egress lookup cap plus the 10 s default request timeout), plus the lock wait, under the
  60 s job timeout.
- The conversation queue `flow.execution` runs on two worker processes; a sleeping step holds one of them.

Task `call-retry-backoff`, T2, decided by the agent on autopilot (unattended) as the cautious default.

## Decision

- **In process, bounded by a window.** `CallRetryPolicy::WINDOW_SECONDS = 18`, counted from the first send. A retry is
  sent only when the elapsed time, its wait, the lookup allowance (`EgressGuardMiddleware::LOOKUP_DEADLINE_SECONDS`) and
  at least one second of request fit; its `options['timeout']` is cut to what is left. A retrying node is therefore
  never slower than the slowest node the segment budget and the lock TTL already assume. When the next send does not
  fit, the node takes `error` with `retry_stopped: window`.
- **The node's verdict decides.** Retries follow `success_statuses`, so an accepted status is never resent. Retryable:
  `transport_failure`, 5xx, 429 (by `status_code`), narrowed by `retry.on`. Never: other 4xx, `egress_denied`,
  `invalid_*`, Solution refusals, a `strict_mapping` miss (the call succeeded), a ledger replay.
- **Config is literal and bounded.** `max_attempts` 1–5 (default 3), `delay_ms` 100–5000 (500), `backoff`
  `exponential` (doubling, capped at 5000 ms) or `fixed`, `on`. `Retry-After` in seconds lengthens the wait; it cannot
  stretch the window. Publish error `call_invalid_retry`.
- **One execution, one unit, one key.** Quota is spent once before the loop; each send asks the ledger for the next
  attempt number; only a success is recorded. Waits use `Illuminate\Support\Sleep`.
- Legacy nodes and the builder's test call (`CallTester`) send once.

## Alternatives

- **Park the session between sends, as `delay` does** (a waiting status and a scheduled resume that re-runs the node
  with the next attempt). Supports long backoff and frees the worker, but needs a park/resume contract for `call`, a
  scheduler entry, an automation segment hand-off and builder semantics for a call that "waits" — a T3 change. Deferred,
  not rejected: it is the way to add backoff longer than the window.
- **A lock-extension hook available to the handler** so it could wait longer in process. Needs a Foundation
  `NodeExecutionContext` change and still holds a worker; rejected for now.
- **A window equal to the lock TTL (30 s).** Breaks the segment arithmetic (30 + 30 + lock wait > 60 s job timeout).
- **Retries inside `HttpTransport`.** Rejected for the same reason as the ledger: the loop must work for every
  transport and stay beside the attempt counter.

## Consequences

- Retries help with fast transient failures (a 503, a 429, a refused connection); a call that timed out at the default
  10 s usually has no time left for another send. Authors lower `timeout` to retry slow calls; the docs say so.
- Changing the lock TTL, the lookup cap or the segment budget means revisiting `WINDOW_SECONDS`.
- Parking for long backoff remains a separate, later design.
