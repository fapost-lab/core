---
id: glossary-conversation
type: glossary
status: active
summary: Conversation thread, transcript, MessageLogEntry, capture, origin, owner, operator takeover
domains:
  - conversation
topics: []
load: domain
paths:
  - "app/Domains/Conversation/**"
  - config/conversation.php
  - "database/migrations/tenant/*conversation*"
  - "tests/*/Domains/Conversation/**"
reviewed_at: 2026-10-05
---
# Conversation glossary

## Conversation

A thread: one per `(tenant, assistant, contact, channel)`. Informal synonyms: dialogue, chat.
Not a flow session.

## Transcript

The append-only list of messages in a thread, stored in the monthly-partitioned
`conversation_messages` table.

## MessageLogEntry

The normalised record of one message that every capture site produces.

## ConversationRef

The key identifying a thread.

## Capture

Building a `MessageLogEntry` at the point where a message is sent or received.

## Origin

Why an outbound message was sent: `flow`, `broadcast`, `notify`, `command`, `system`, `staff`
(`MessageOrigin`).

## Owner

Who handles a thread (`ConversationOwner`, column `owner_type`): `bot` (null reads as bot), `queued`
(an operator was requested and nobody accepted yet, since `queued_at`) or `staff` (the operator in
`owner_staff_user_id`). This is the thread's only state: the old `status` (`open` / `closed` / `snoozed`)
is retired, kept as a column nobody reads. Informal synonym: handling.

## Holder

The operator a `staff` thread belongs to. Only the holder replies, finishes or transfers.

## Operator takeover

Switching a thread to staff: **take over** from `bot`, **accept** from `queued` (the same operation),
**seize** (an admin takes a thread from its holder), **transfer** (the holder hands it to another
operator). **Finish** returns it to the bot. While a thread is `queued` or `staff`, inbound messages do
not reach flows.

## Inbox

The staff view of one assistant's threads, with `unread_count` and the handling filters `queued`,
`mine`, `staff` and `bot`.
