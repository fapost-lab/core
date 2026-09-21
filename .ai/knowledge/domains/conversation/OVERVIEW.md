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
---
# Conversation

## Responsibility

Conversation keeps an append-only transcript of every inbound and outbound message, grouped into
threads, and owns who answers a thread: the bot or a staff operator (operator takeover). It also
provides operator replies and the staff inbox.

## Write path

Capture sites build a `MessageLogEntry` through `ConversationCaptureFactory` and hand it to
`ConversationLoggerInterface`. The logger queues `PersistConversationMessageJob`
(`messaging.logging`), which writes through `ConversationStoreInterface`. The capture sites are
Webhook's `IncomingMessageJob`, `MessageSender`, Flow's `FlowMessageSender` and
`ConversationReplyService`.

## Storage port

Only the write side is behind a port. `ConversationStoreInterface` has one implementation,
`Store/PostgresConversationStore`, chosen by `config('conversation.driver')` (any other value
throws). Reads — the Filament conversation view, ownership, operator replies — use the Eloquent
models directly, and no read-side interface exists. The store's `ensureConversation` creates the
Postgres `conversations` row that ownership, the inbox and unread counts depend on. *(inferred:
a second storage backend would still need that row in Postgres)*

## Boundaries

- Bindings (all `scoped`): logger, store, ownership, reply service
  (`Providers/ConversationServiceProvider.php`).
- Depends on Tenancy, Media (ingestor, dispatcher, `MediaFile`), the `Channel` model, Contact's
  `Contact` and `ChannelContact`, and Staff.
- Consumers: Webhook and Channels' `MessageSender` (capture and logger); Flow's `MessageRouter`,
  which checks staff ownership on every inbound message; Contact and Broadcasting (the
  `MessageOrigin` enum only).

## Entry points

- `Services/ConversationLogger.php`, `Capture/`, `Store/PostgresConversationStore.php`.
- Jobs on `messaging.logging`: `PersistConversationMessageJob`, `FetchConversationMediaJob`,
  `UpdateConversationDeliveryStatusJob`.
- Admin UI: `app/Filament/Assistant/Resources/Conversations`.
