# Conversation Transcript: Design Decisions

**Status:** Accepted, largely implemented (see "Status (2026-10)" at the end).
**Original design date:** June 2026.
**Current description of the domain:** [`.ai/knowledge/domains/conversation/OVERVIEW.md`](/.ai/knowledge/domains/conversation/OVERVIEW.md).
This document records why the domain looks the way it does; it is not a task list.

## Context

The platform had no product-level conversation history:

| Layer | Purpose | Why it is not a transcript |
|-------|---------|----------------------------|
| `flow_logs` | per-node engine execution, 30 days, partitioned | engine diagnostics, expires |
| `flow_session_history` | state changes inside a session (opt-in `logging_enabled`) | state changes, not messages; tied to a session, not a contact |
| `analytics_events` | aggregated business events | counters only, no content |
| `contacts.attributes`, `flow_sessions.state` | data saved by `input`/`assign` | only what a node explicitly stored; a message "past the input" is nowhere |

A separate, continuous transcript (inbound from the contact, outbound from the bot) was needed,
independent of any flow session's lifecycle and usable for audit and for an Inbox / human takeover.

## Decisions

1. **Two-level model: `Conversation` aggregate + `conversation_messages`.** The aggregate carries what an
   inbox needs (status open/closed/snoozed, `last_message_at`, `unread_count`, owner bot/staff), so the
   audit transcript and the Inbox share one model.
2. **Log everything:** inbound from contacts, outbound from flows, broadcasts per message, notify
   messages to contacts, staff replies. Staff-mode notifications (internal platform alerts) are not
   messages to a contact and are not logged.
3. **Permanent storage behind a swappable backend, written asynchronously.** Default driver is PostgreSQL
   in the tenant schema with monthly partitions; a column store (ClickHouse) is meant to plug in behind the
   same port without touching the pipeline. Asynchronous writes keep the hot path free and fit batching.
4. **Port invariant (the main architectural rule).** Nothing outside a storage driver knows where messages
   physically live. Capture sites emit a normalised `MessageLogEntry` into the write port; reads go through
   a read port; Eloquent relations onto `conversation_messages` are forbidden, otherwise a ClickHouse
   switch breaks the UI. ClickHouse lives outside the schema-per-tenant model (own database, `tenant_id`
   in the sort key), which is acceptable because the log takes no part in core transactions.
5. **Threads are scoped by channel.** Thread identity is (tenant, assistant, contact, **channel**) with
   `unique(tenant_id, assistant_id, contact_id, channel_id)`; the same contact writing through two
   channels has two threads.
6. **Separate low-priority queue `messaging.logging`** (own Horizon supervisor) so log volume, broadcasts
   in particular, never competes with transactional traffic. A logging failure never breaks message
   processing (the logger swallows and logs errors).
7. **Contracts stay in Core.** `MessageLogEntry` and the ports live in `app/Domains/Conversation/`, not in
   Foundation: extensions do not write to the transcript.
8. **Messages survive deletions.** `conversation_messages` is partitioned with PK `(id, created_at)` and
   has no foreign keys; the thread link is logical. Postgres also forces the partition key into the
   idempotency index: `unique(conversation_id, direction, idempotency_key, created_at)`; retries replay the
   same serialised entry, so duplicates still collide and NULL keys insert freely.
9. **Media is not duplicated.** The Media domain is reused: the message stores a descriptor
   (`media_file_id`, kind, mime, size, `provider_file_id`), never bytes. Inbound download is asynchronous
   (`FetchConversationMediaJob`, descriptor starts `pending`, `failed` keeps the provider id). Ingested
   files use `MediaSource::Conversation`, filtered out of the admin media library by `MediaService`.
10. **Takeover and inbox are modelled in the aggregate.** Ownership is small mutable per-thread state kept
    in the operational database behind its own port (`ConversationOwnershipInterface`), apart from the
    append-only message store, so it stays put when message storage moves. The router asks
    `isHandledByStaff()` on each inbound message and the flow engine stands down while an operator holds the thread.
11. **No new content-type vocabulary per platform.** Capture sites translate platform/domain types into one
    canonical `MessageContentType` (`text, photo, document, video, voice, audio, location, contact,
    callback, unknown`); a keyboard travels in `payload`. A button press is `callback` with
    `payload {value, button_id}` where `value` is language-agnostic.

## Capture sites

- **Inbound:** `IncomingMessageJob`, after normalisation and contact/assistant/channel resolution and
  **before** `MessageRouter::route()`. Messages dropped by the concurrency policy are real user messages
  and must be kept; the log must not depend on whether a flow ran. Idempotency key is the provider update
  id (the same one used by ingress dedup).
- **Outbound:** `MessageSender::send()`, the single outbound funnel, after `DeliveryResult`. One site
  covers flows, notify and broadcasts. Context (`contact_id`, `assistant_id`, `origin`, `origin_ref`)
  travels in `OutboundMessage.metadata`; the idempotency key is the existing `OutboundMessage.idempotencyKey`.
  Duplicate deliveries collapse on the unique index.
- **Staff replies:** `ConversationReplyService` sends through the same funnel with `sender_type = staff`.

## Write flow

```
IncomingMessageJob / MessageSender / BroadcastSendJob
        -> MessageLogEntry
        -> ConversationLoggerInterface::log()      (non-blocking, scoped, tenant-aware)
        -> PersistConversationMessageJob           (queue messaging.logging)
        -> ConversationStoreInterface              (driver from config/conversation.php)
             ensureConversation (upsert aggregate) + appendMessage (idempotent)
```

A column-store driver may buffer and batch inside the driver; the port does not change.

## Boundaries

- Does not duplicate `flow_logs` (diagnostics, short retention) and does not write into flow state.
- Does not log staff notifications as messages to a contact.
- Does not move the contracts to Foundation.

## Status (2026-10)

**Built:**

- Domain `app/Domains/Conversation/`: write port `ConversationLoggerInterface`, storage port
  `ConversationStoreInterface` (`ensureConversation`, `appendMessage`, `updateMessageMedia`, `updateStatus`),
  ownership port `ConversationOwnershipInterface`, reply port `ConversationReplyServiceInterface`;
  `PostgresConversationStore`, `ConversationPartitionManager`; jobs `PersistConversationMessageJob`,
  `FetchConversationMediaJob`, `UpdateConversationDeliveryStatusJob`; `config/conversation.php`
  (`driver`, `enabled`, `retention_days`, `queue`, `media.fetch`).
- Tenant migrations `2026_06_12_000001_create_conversations_table.php` and
  `2026_06_12_000002_create_conversation_messages_table.php` (partitioned on PostgreSQL, plain table on
  sqlite tests).
- Capture on inbound (`IncomingMessageJob`) and outbound (`MessageSender`, with flow and broadcast
  metadata); media fetch via the Media domain; `messaging.logging` queue in Horizon.
- Filament `ConversationResource` (list and view) with operator reply and ownership handover; the router
  skips the flow engine for staff-owned threads.

**Differs from the original design:**

- The Filament inbox reads the Eloquent models `Conversation` / `ConversationMessage` directly, so the
  port invariant in decision 4 is not yet enforced on the read side.
- Deleting a contact cascades only to `conversations` (FK `cascadeOnDelete`); messages have no FK and
  remain as orphans, so a GDPR erasure does not remove them today.

**Not built (moved to the spec `.ai/specs/data-lifecycle/`):**

- Read port `ConversationReaderInterface` and a ClickHouse driver (the Jig task `conversation-reader-interface`
  covers the reader port).
- Retention: `conversation.retention_days` exists but nothing reads it; there is no partition pruning.
- Partitions are created on write (`ensureMonthlyPartition` per insert) plus the three created by the
  migration; there is no scheduled pre-creation.
- PII redaction hook (`PiiRedactorInterface`); text is stored verbatim.
- Delivery status ingestion: `updateDeliveryStatus()` and its job exist, but no webhook (WhatsApp
  sent/delivered/read) calls them yet.
- Message and media erasure on contact deletion.

## Open questions

- ClickHouse tenant isolation: one table with `tenant_id` in the sort key versus a database per tenant;
  decided when the driver is built.
- Broadcast volume with permanent retention is the first candidate pressure toward ClickHouse.
