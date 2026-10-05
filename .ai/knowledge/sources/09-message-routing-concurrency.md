---
id: adr-09-message-routing-concurrency
type: adr
status: accepted
domains:
  - flow
paths:
  - "app/Domains/Flow/Routing/**"
  - "app/Domains/Flow/Concurrency/**"
  - app/Infrastructure/Flow/FlowExecutionGuard.php
source: docs/platform/architecture/adr/09-message-routing-concurrency.md
summary: Message routing pipeline, global commands and the Redis session lock with heartbeat
source_hash: 59b9c46ab3d859e48e162742c356849e96f22185
reviewed_at: 2026-10-05
---
# ADR-09 — Message Routing & Concurrency Control

Linked source: [docs/platform/architecture/adr/09-message-routing-concurrency.md](../../../docs/platform/architecture/adr/09-message-routing-concurrency.md). The source owns its rules; this document only says when an agent
must read it.
