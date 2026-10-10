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
  - app/Http/Controllers/Console/BroadcastController.php
  - "app/Http/Requests/Console/*Broadcast*"
  - "resources/js/pages/Console/Broadcasts/**"
  - tests/Feature/Console/BroadcastsConsoleTest.php
  - tests/Feature/Filament/EditBroadcastTest.php
reviewed_at: 2026-10-05
---
# Broadcasting rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **Only one caller starts a broadcast.** `Draft` → `Running` is a conditional UPDATE. Enforced:
  `BroadcastDispatcher`, `BroadcastDispatcherTest`.
- **A broadcast is changed only while it is a draft, and the check is part of the write.** A
  started run reads `message` for each recipient as it delivers, so a later edit would change what
  the rest of the audience receives. The console's `BroadcastService::update()` and Filament's
  `EditBroadcast::handleRecordUpdate()` lock the row and look at the status again. Enforced:
  `BroadcastsConsoleTest`, `EditBroadcastTest`.
- **The console sends only the revision a person confirmed.** `BroadcastService::send()` locks the
  draft and refuses a stale revision (a hash of name, message, target; not `updated_at`), a base
  language without text and an audience that no longer exists, then lets
  `BroadcastDispatcher::start()` decide the single winner. Enforced: `BroadcastsConsoleTest`.
- **The run is queued after the transaction commits** (`->afterCommit()` in
  `BroadcastDispatcher::start()`), so a job never sees a draft that is already `Running` on the
  other side. Enforced: `BroadcastDispatcherTest`.
- **The reach shown to a person is `BroadcastRecipientResolver::count()`, equal to
  `resolve()->count()`** (one shared query). Enforced: `BroadcastRecipientResolverCountTest`.
- **Recipients are written once:** `insertOrIgnore` plus a unique `(broadcast_id, contact_id)`.
- **Backpressure is checked before any recipient row is written;** the run re-queues itself with
  a 30-second delay (`RunBroadcastJob`). No test covers it.
- **A send job acts only on a `Pending` recipient,** and only the last recipient to finish flips
  the broadcast to `Completed`, through a conditional update (`SendBroadcastRecipientJob`).
- **Every job runs inside `TenantSwitcher::runForTenant()`.**

- **A volume refusal cancels the run.** The first recipient refused for `outbound_messages` is
  Skipped with the reason and the broadcast moves Running → Cancelled with `stop_reason`
  (`limit_reached`), set by one conditional update; later recipient jobs of a Cancelled broadcast
  skip without asking the operator. "Completed is set only by the last recipient" therefore holds
  for runs that were not stopped. Notify sends (`BroadcastSendJob`) log the refusal and drop.
  Enforced: `OutboundVolumeLimitTest`, `BroadcastSendJobTest`.

## Rules

- **Send only through `MessageSenderInterface`.** Why: idempotency, the chat rate limit and the
  transcript live there. Review only. *(proposed)*
- **Backpressure reads the size of a queue shared by all tenants.** A change to the backpressure
  logic has to consider fairness between tenants, not only this tenant's load. *(inferred)*
