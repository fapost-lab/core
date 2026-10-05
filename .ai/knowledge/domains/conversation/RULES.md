---
id: rule-conversation
type: rule
status: active
summary: Dedup per idempotency key, logger never throws, staff ownership blocks flows, no FK on messages
domains:
  - conversation
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Conversation/**"
  - config/conversation.php
  - "app/Filament/Assistant/Resources/Conversations/**"
  - "database/migrations/tenant/*conversation*"
  - "tests/*/Domains/Conversation/**"
reviewed_at: 2026-10-05
---
# Conversation rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **A transcript message is stored once** per `(conversation_id, direction, idempotency_key)`: an
  explicit check plus a unique index that includes `created_at`, the partition key. Enforced:
  `PostgresConversationStore`, `PostgresConversationStoreTest`.
- **Thread creation is race-safe** (`createOrFirst`).
- **The logger never throws to its caller.** Why: a transcript failure must not break message
  delivery or flow execution. Enforced: `ConversationLogger`.
- **A staff-owned thread stops inbound routing before flow execution** (`MessageRouter`).
- **An operator reply requires staff ownership, checked on the server;** returning a thread to
  the bot clears the operator (`ConversationOwnership`).

## Rules

- **Write to the transcript only through `ConversationLoggerInterface` with a capture.** Never
  insert into `conversation_messages` directly. Review only. *(proposed)*
- **Keep storage-specific code behind `ConversationStoreInterface`.** New read features should
  not add direct `ConversationMessage` queries; the Filament conversation view is the existing
  exception. Review only. *(proposed)*
- **`conversation_messages` has no foreign key** (the table is partitioned), so deleting a
  contact or thread leaves its messages behind. Deletion and retention work must remove them
  explicitly.
