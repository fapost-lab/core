---
id: rule-broadcasting
type: rule
status: active
summary: Single winner on start, recipients written once, backpressure before writes, send via MessageSender
domains:
  - broadcasting
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Broadcasting/**"
  - "app/Filament/Assistant/Resources/Broadcasts/**"
  - "database/migrations/tenant/*broadcast*"
  - "tests/Feature/Domains/Broadcasting/**"
---
# Broadcasting rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **Only one caller starts a broadcast.** `Draft` → `Running` is a conditional UPDATE. Enforced:
  `BroadcastDispatcher`, `BroadcastDispatcherTest`.
- **Recipients are written once:** `insertOrIgnore` plus a unique `(broadcast_id, contact_id)`.
- **Backpressure is checked before any recipient row is written;** the run re-queues itself with
  a 30-second delay (`RunBroadcastJob`). No test covers it.
- **A send job acts only on a `Pending` recipient,** and only the last recipient to finish flips
  the broadcast to `Completed`, through a conditional update (`SendBroadcastRecipientJob`).
- **Every job runs inside `TenantSwitcher::runForTenant()`.**

## Rules

- **Send only through `MessageSenderInterface`.** Why: idempotency, the chat rate limit and the
  transcript live there. Review only. *(proposed)*
- **Backpressure reads the size of a queue shared by all tenants.** A change to the backpressure
  logic has to consider fairness between tenants, not only this tenant's load. *(inferred)*
