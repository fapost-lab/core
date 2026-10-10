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
  - "app/Filament/Assistant/Resources/Broadcasts/**"
  - "database/migrations/tenant/*broadcast*"
  - "tests/Feature/Domains/Broadcasting/**"
  - app/Http/Controllers/Console/BroadcastController.php
  - "app/Http/Requests/Console/*Broadcast*"
  - "resources/js/pages/Console/Broadcasts/**"
  - tests/Feature/Console/BroadcastsConsoleTest.php
reviewed_at: 2026-10-05
---
# Broadcasting

## Responsibility

A broadcast is a one-off message to an assistant's audience — everyone, contacts with given
tags, or a segment. `RunBroadcastJob` writes one recipient row per contact, then dispatches one
`SendBroadcastRecipientJob` per recipient in timed batches. Each send goes through
`MessageSender`, which also records the transcript.

## Boundaries

- There is no service provider. The services are concrete classes resolved by the container;
  the Filament table calls `app(BroadcastDispatcher::class)`, the console goes through
  `BroadcastService`.
- Depends on Contact (the tag repository contract, and the concrete `ContactSegmentResolver`,
  `ContactSegment`, `ChannelContact`, `Contact`), Flow's `ContentTranslatorInterface`, the
  `Channel` and `Assistant` models, Conversation's `MessageOrigin`, Tenancy, and the foundation
  `MessageSenderInterface`.
- The only consumers are the two admin UIs: the console (Inertia, `UI_INERTIA` on) and Filament
  (the default).
- Tenant settings `broadcast_*` in `TenantSettings` control backpressure and batching.

## Entry points

- `Services/BroadcastDispatcher.php`, `Services/BroadcastRecipientResolver.php`.
- Jobs on `messaging.broadcast`: `Jobs/RunBroadcastJob.php`, `Jobs/SendBroadcastRecipientJob.php`.
- Console UI: `app/Http/Controllers/Console/BroadcastController.php` over
  `Services/BroadcastService.php` (the console's writes and the send guard), with the requests in
  `app/Http/Requests/Console/*Broadcast*` and the pages in `resources/js/pages/Console/Broadcasts`.
- Filament UI: `app/Filament/Assistant/Resources/Broadcasts`. Both UIs share
  `Support/BroadcastMessage.php` for cleaning the message and the base-language check.
