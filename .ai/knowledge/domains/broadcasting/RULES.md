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
  - "database/migrations/tenant/*broadcast*"
  - "tests/Feature/Domains/Broadcasting/**"
  - app/Http/Controllers/Console/BroadcastController.php
  - "app/Http/Requests/Console/*Broadcast*"
  - "resources/js/pages/Console/Broadcasts/**"
  - tests/Feature/Console/BroadcastsConsoleTest.php
  - app/Http/Controllers/Console/BroadcastReportController.php
  - tests/Feature/Console/BroadcastReportConsoleTest.php
reviewed_at: 2026-11-27
---
# Broadcasting rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **Only one caller starts a broadcast.** `Draft` → `Running` is a conditional UPDATE. Enforced:
  `BroadcastDispatcher`, `BroadcastDispatcherTest`.
- **A broadcast is changed only while it is a draft, and the check is part of the write.** A
  started run reads `message` for each recipient as it delivers, so a later edit would change what
  the rest of the audience receives. The console's `BroadcastService::update()` locks the row and
  looks at the status again. Enforced: `BroadcastsConsoleTest`.
- **The console sends only the revision a person confirmed.** `BroadcastService::send()` locks the
  draft and refuses a stale revision (a hash of name, message, target; not `updated_at`), a base
  language without text and an audience that no longer exists, then lets
  `BroadcastDispatcher::start()` decide the single winner. Enforced: `BroadcastsConsoleTest`.
- **An automation starts a broadcast only through `AutomationBroadcasts`, once per node execution and never while the
  node's previous one is live.** A flow's `send_message` to tags or a segment has no revision to confirm (publishing the
  flow was the confirmation, and needs `ManageBroadcast` for the node and for what leads to it,
  `adr-20261125-broadcast-publish-right-covers-what-leads-to-it`), no audit record and no current assistant (the broadcast is
  the flow's assistant's). `origin_key` is unique, so a retried step gets its broadcast back; a partial unique index on
  `(flow_id, flow_node_id)` over `draft`/`running` rows refuses a second live one (`broadcast_running`). The insert runs
  in its own transaction so a unique violation can be read back on PostgreSQL. Its completion event carries
  `chain_depth` (`adr-20261123-flow-audience-message-through-broadcasting`). Everyone (`BroadcastTarget::All`) is an
  audience here only because a legacy `notify` node in `contacts` mode names it; `send_message` has no such addressee,
  and that mode can no longer be added or changed (`adr-20261125-notify-contacts-retired-through-broadcasting`).
  Enforced: `AutomationBroadcastsTest`, `SendMessageBroadcastTest`, `NotifyContactsBroadcastTest`.
- **The run is queued after the transaction commits** (`->afterCommit()` in
  `BroadcastDispatcher::start()`), so a job never sees a draft that is already `Running` on the
  other side. Enforced: `BroadcastDispatcherTest`.
- **The reach shown to a person is `BroadcastRecipientResolver::count()`, equal to
  `resolve()->count()`** (one shared query). Enforced: `BroadcastRecipientResolverCountTest`.
- **Recipients are written once:** `insertOrIgnore` plus a unique `(broadcast_id, contact_id)`.
- **Backpressure is checked before any recipient row is written;** the run re-queues itself with
  a 30-second delay (`RunBroadcastJob`). No test covers it.
- **A send job starts only from a `Pending` recipient,** and only the last recipient to finish flips
  the broadcast to `Completed`, through a conditional update (`SendBroadcastRecipientJob`).
  The job's `finish()` calls `BroadcastService::settleRecipient()`, which settles the row only from
  `Pending`; if a failed run skipped it (`Skipped`, `run_failed`) while its send was already in
  flight, it takes the row back to the real outcome and moves that one unit from `skipped_count` to
  the matching counter (one UPDATE, one transaction), so the counters never exceed
  `total_recipients`. The settle/reclaim lives only in the service. Both `settleRecipient()` and
  `failRun()` lock the broadcast row first, then the recipient rows; the other order deadlocks on
  Postgres. Enforced: `SendBroadcastRecipientJobTest` (the lock order is not testable on sqlite).
- **Every job runs inside `TenantSwitcher::runForTenant()`.**

- **A crashed run fails the broadcast.** When `RunBroadcastJob` exhausts its attempts, `failed()`
  calls `BroadcastService::failRun()`, which moves Running → Failed with `stop_reason` `run_failed`
  by one conditional update (a Cancelled or Completed run is left alone) and skips the still-Pending
  recipients; later recipient jobs of a Failed broadcast skip like Cancelled ones.
  Enforced: `RunBroadcastJobTest`, `SendBroadcastRecipientJobTest`.

- **A volume refusal cancels the run.** The first recipient refused for `outbound_messages` is
  Skipped with the reason and the broadcast moves Running → Cancelled with `stop_reason`
  (`limit_reached`), set by one conditional update; later recipient jobs of a Cancelled broadcast
  skip without asking the operator. "Completed is set only by the last recipient" therefore holds
  for runs that were not stopped.
  Enforced: `OutboundVolumeLimitTest`.

- **A recipient's stored `error` never leaves the server.** It is whatever the send path recorded — for a failed send,
  the sender's exception message, which can carry the provider's request URL and with it the bot token. The broadcast
  report reads it only to choose a `RecipientFailureReason` category, and only the category reaches the page. The texts
  the send path writes are therefore a contract the report reads: `run_failed` and `limit_reached` (the run's stop
  reasons), `RecipientFailureReason::CONTACT_MISSING_ERROR` and `NO_TEXT_ERROR`; a new skip or fail reason gets its
  own marker and category, or it reads as "stopped" / "delivery failed". Enforced: `BroadcastReportConsoleTest`
  (`test_the_stored_error_text_never_reaches_the_page`), `RecipientFailureReasonTest`, `SendBroadcastRecipientJobTest`.

## Rules

- **Send only through `MessageSenderInterface`.** Why: idempotency, the chat rate limit and the
  transcript live there. Review only. *(proposed)*
- **Backpressure reads the size of a queue shared by all tenants.** A change to the backpressure
  logic has to consider fairness between tenants, not only this tenant's load. *(inferred)*
