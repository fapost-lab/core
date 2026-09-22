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
source_hash: b5ccc7d1a006cd073c06ff07c15e99f71e45e4b8
reviewed_at: 2026-09-22
---
# FlowSession — жизненный цикл сессии

Linked source: [docs/reference/diagrams/04-session-state-machine.md](../../../docs/reference/diagrams/04-session-state-machine.md). The source owns its rules; this document only says when an agent
must read it.
