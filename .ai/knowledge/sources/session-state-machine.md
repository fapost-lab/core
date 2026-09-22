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
source_hash: 4154c1a56aae6111382d5c8c0bb9403e11575395
---
# FlowSession — жизненный цикл сессии

Linked source: [docs/reference/diagrams/04-session-state-machine.md](../../../docs/reference/diagrams/04-session-state-machine.md). The source owns its rules; this document only says when an agent
must read it.
