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
source_hash: 18343408ce1e19050af7bcc31bf18282d26c4e91
reviewed_at: 2026-09-22
---
# FlowSession — жизненный цикл сессии

Linked source: [docs/reference/diagrams/04-session-state-machine.md](../../../docs/reference/diagrams/04-session-state-machine.md). The source owns its rules; this document only says when an agent
must read it.
