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
---
# Broadcasting

## Responsibility

A broadcast is a one-off message to an assistant's audience — everyone, contacts with given
tags, or a segment. `RunBroadcastJob` writes one recipient row per contact, then dispatches one
`SendBroadcastRecipientJob` per recipient in timed batches. Each send goes through
`MessageSender`, which also records the transcript.

## Boundaries

- There is no service provider. The services are concrete classes resolved by the container;
  the Filament table calls `app(BroadcastDispatcher::class)`.
- Depends on Contact (the tag repository contract, and the concrete `ContactSegmentResolver`,
  `ContactSegment`, `ChannelContact`, `Contact`), Flow's `ContentTranslatorInterface`, the
  `Channel` and `Assistant` models, Conversation's `MessageOrigin`, Tenancy, and the foundation
  `MessageSenderInterface`.
- The only consumer is the admin UI.
- Tenant settings `broadcast_*` in `TenantSettings` control backpressure and batching.

## Entry points

- `Services/BroadcastDispatcher.php`, `Services/BroadcastRecipientResolver.php`.
- Jobs on `messaging.broadcast`: `Jobs/RunBroadcastJob.php`, `Jobs/SendBroadcastRecipientJob.php`.
- Admin UI: `app/Filament/Assistant/Resources/Broadcasts`.
