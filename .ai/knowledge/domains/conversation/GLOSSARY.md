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
  - "app/Filament/Assistant/Resources/Conversations/**"
  - "database/migrations/tenant/*conversation*"
  - "tests/*/Domains/Conversation/**"
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

Who answers a thread: `bot` or `staff`.

## Operator takeover

Switching a thread's owner to staff. While staff owns it, inbound messages do not reach flows.

## Inbox

The staff view of threads, with `unread_count` and a status of `open`, `closed` or `snoozed`.
