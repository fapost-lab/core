---
id: domain-broadcasting
type: domain
status: active
summary: "One-off messages to all, tags or a segment: recipient rows, batched sends, backpressure"
domains:
  - broadcasting
topics: []
load: domain
paths:
  - "app/Domains/Broadcasting/**"
  - "database/migrations/tenant/*broadcast*"
  - "tests/Feature/Domains/Broadcasting/**"
  - app/Http/Controllers/Console/BroadcastController.php
  - "app/Http/Requests/Console/*Broadcast*"
  - "resources/js/pages/Console/Broadcasts/**"
  - tests/Feature/Console/BroadcastsConsoleTest.php
reviewed_at: 2026-11-19
---
# Broadcasting

## Responsibility

A broadcast is a one-off message to an assistant's audience — everyone, contacts with given
tags, or a segment. `RunBroadcastJob` writes one recipient row per contact, then dispatches one
`SendBroadcastRecipientJob` per recipient in timed batches. Each send goes through
`MessageSender`, which also records the transcript.

## Boundaries

- There is no service provider. The services are concrete classes resolved by the container;
  the console goes through `BroadcastService`.
- Depends on Contact (the tag repository contract, and the concrete `ContactSegmentResolver`,
  `ContactSegment`, `ChannelContact`, `Contact`), Flow's `ContentTranslatorInterface`, the
  `Channel` and `Assistant` models, Conversation's `MessageOrigin`, Tenancy, and the foundation
  `MessageSenderInterface`.
- The console (`BroadcastController`, Inertia) drives it. The domain raises `Events/BroadcastCompleted` when a
  broadcast completes (only from the conditional update that wins); Flow listens and publishes it as the Core event
  `core.broadcast.completed`, so Flow now imports this event class (`adr-20261119-core-events-start-contactless-runs`).
  The event carries the broadcast's `chain_depth`, which `PublishCoreEvents` publishes it with.
- Tenant settings `broadcast_*` in `TenantSettings` control backpressure and batching.

## Entry points

- `Services/BroadcastDispatcher.php`, `Services/BroadcastRecipientResolver.php`.
- Jobs on `messaging.broadcast`: `Jobs/RunBroadcastJob.php`, `Jobs/SendBroadcastRecipientJob.php`. A run that exhausts its attempts
  reaches `BroadcastService::failRun()` from `RunBroadcastJob::failed()`, a queue path with no console user;
  `SendBroadcastRecipientJob` records each outcome through `BroadcastService::settleRecipient()`, the other queue-path entry.
- Console UI: `app/Http/Controllers/Console/BroadcastController.php` over
  `Services/BroadcastService.php` (the console's writes and the send guard), with the requests in
  `app/Http/Requests/Console/*Broadcast*` and the pages in `resources/js/pages/Console/Broadcasts`. The list shows a row's `stop_reason` under its status.
- `Services/AutomationBroadcasts.php`: the automation's entry, called by Flow's `send_message` and by a `notify` node
  published in its retired `contacts` mode, through `App\Infrastructure\Flow\BroadcastingAudienceBroadcaster`
  (`AudienceBroadcasterInterface`); it creates the broadcast as `origin = automation` and starts it at once. The console
  list marks such rows and names their flow.
- `Support/BroadcastMessage.php` cleans the message and checks the base language, for the console's
  request and service.
