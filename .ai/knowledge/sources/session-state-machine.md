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
source_hash: 66916e2df609a80d8475b54215854877c9aec522
reviewed_at: 2026-11-25
---
# FlowSession: the session lifecycle

Linked source: [docs/reference/diagrams/04-session-state-machine.md](../../../docs/reference/diagrams/04-session-state-machine.md). The source owns its rules; this document only says when an agent
must read it.
