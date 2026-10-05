---
id: domain-conversation
type: domain
status: active
summary: Append-only transcript, capture-to-store write path, bot vs staff ownership, inbox
domains:
  - conversation
topics: []
load: domain
paths:
  - "app/Domains/Conversation/**"
  - config/conversation.php
  - "app/Filament/Assistant/Resources/Conversations/**"
  - "database/migrations/tenant/*conversation*"
  - "tests/*/Domains/Conversation/**"
reviewed_at: 2026-10-05
---
# Conversation

## Responsibility

Conversation keeps an append-only transcript of every inbound and outbound message, grouped into
threads, and owns who answers a thread: the bot or a staff operator (operator takeover). It also
provides operator replies and the staff inbox.

## Write path

Capture sites build a `MessageLogEntry` through `ConversationCaptureFactory` and hand it to
`ConversationLoggerInterface`. The logger queues `PersistConversationMessageJob`
(`messaging.logging`), which writes through `ConversationStoreInterface`
(`ensureConversation`, `appendMessage`, `updateMessageMedia`, `updateStatus`) and, for inbound media,
dispatches `FetchConversationMediaJob`. The capture sites are Webhook's `IncomingMessageJob`,
`MessageSender` (`app/Domains/Messaging/MessageSender.php`, Messaging — not Channels), Flow's
`FlowMessageSender` and `ConversationReplyService`. Inbound is logged before routing, outbound after
the delivery result.
`ConversationLogger::updateDeliveryStatus` queues `UpdateConversationDeliveryStatusJob`, but no caller
outside the domain uses it, so delivery statuses are not updated in practice.

## Storage port

Only the write side is behind a port. `ConversationStoreInterface` has one implementation,
`Store/Postgres/PostgresConversationStore` (with `ConversationPartitionManager`), chosen by `config('conversation.driver')` (any other value
throws). Reads — the Filament conversation view, ownership, operator replies — use the Eloquent
models directly, and no read-side interface exists. The store's `ensureConversation` creates the
Postgres `conversations` row that ownership, the inbox and unread counts depend on. *(inferred:
a second storage backend would still need that row in Postgres)* There is no ClickHouse store, and a
second backend would need a read port first.

`conversation_messages` is a range-partitioned table with no foreign keys, so messages survive the
deletion of the contact, assistant or channel (the `conversations` row cascades). Monthly partitions
are created only on write (`ensureMonthlyPartition` in the store); `retention_days` in
`config/conversation.php` is never read, so nothing prunes the transcript. The dedup index
`conversation_messages_idem_unique` includes `created_at`, the partition key.

## Takeover

`ConversationOwnershipInterface` (`ConversationOwnership`) holds who answers a thread: `assign()`
switches between bot and a staff user, `isHandledByStaff()` is read by Flow's `MessageRouter` on every
inbound message. Operators reply through `ConversationReplyService`.

## Boundaries

- Bindings (all `scoped`): logger, store, ownership, reply service
  (`Providers/ConversationServiceProvider.php`).
- Depends on Tenancy, Media (ingestor, dispatcher, `MediaFile`), the `Channel` model, Contact's
  `Contact` and `ChannelContact`, and Staff.
- Consumers: Webhook and Messaging's `MessageSender` (capture and logger); Flow's `MessageRouter`,
  which checks staff ownership on every inbound message; Contact and Broadcasting (the
  `MessageOrigin` enum only).

## Entry points

- `Services/ConversationLogger.php`, `Capture/`, `Store/Postgres/PostgresConversationStore.php`.
- Jobs on `messaging.logging`: `PersistConversationMessageJob`, `FetchConversationMediaJob`,
  `UpdateConversationDeliveryStatusJob`.
- Admin UI: `app/Filament/Assistant/Resources/Conversations`.
