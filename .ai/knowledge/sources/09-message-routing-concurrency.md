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
source_hash: 32f3d2fa621d6d23a729834c8285cc1b8ee0f056
reviewed_at: 2026-11-25
---
# ADR-09 — Message Routing & Concurrency Control

Linked source: [docs/platform/architecture/adr/09-message-routing-concurrency.md](../../../docs/platform/architecture/adr/09-message-routing-concurrency.md). The source owns its rules; this document only says when an agent
must read it.
