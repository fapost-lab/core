---
id: feature-session-state-machine
type: feature
status: active
domains:
  - flow
paths:
  - app/Domains/Flow/Enums/FlowSessionStatus.php
  - app/Domains/Flow/Services/FlowSessionPersister.php
source: docs/reference/diagrams/04-session-state-machine.md
summary: Flow session statuses and the transitions the code makes between them
source_hash: 644bfbcffebab4029f90f5806ef0a9d5e4d83448
reviewed_at: 2026-10-05
---
# FlowSession: the session lifecycle

Linked source: [docs/reference/diagrams/04-session-state-machine.md](../../../docs/reference/diagrams/04-session-state-machine.md). The source owns its rules; this document only says when an agent
must read it.
