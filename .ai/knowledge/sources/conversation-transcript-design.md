---
id: adr-conversation-transcript-design
type: adr
status: accepted
domains:
  - conversation
paths:
  - "app/Domains/Conversation/**"
  - config/conversation.php
source: docs/reference/specs/messaging/conversation-logging.md
summary: "Why the conversation transcript is a separate port-backed domain: invariants, channel-scoped threads, queue, partitioning, takeover, and what is not built"
source_hash: 0ec962c9b998f9a3afd6360df3bda9848a488bbe
reviewed_at: 2026-11-25
---
# Conversation Transcript: Design Decisions

Linked source: [docs/reference/specs/messaging/conversation-logging.md](../../../docs/reference/specs/messaging/conversation-logging.md). The source owns its rules; this document only says when an agent
must read it.
