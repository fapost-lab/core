---
id: adr-conversation-transcript-design
type: adr
status: proposed
domains:
  - conversation
paths:
  - "app/Domains/Conversation/**"
  - config/conversation.php
source: docs/reference/specs/messaging/conversation-logging.md
summary: "Why the conversation transcript is a separate port-backed domain: invariants, channel-scoped threads, queue, partitioning, takeover, and what is not built"
---
# Conversation Transcript: Design Decisions

Linked source: [docs/reference/specs/messaging/conversation-logging.md](../../../docs/reference/specs/messaging/conversation-logging.md). The source owns its rules; this document only says when an agent
must read it.
