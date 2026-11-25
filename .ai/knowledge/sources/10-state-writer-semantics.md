---
id: adr-10-state-writer-semantics
type: adr
status: accepted
domains:
  - flow
paths:
  - "app/Domains/Flow/History/**"
  - app/Domains/Flow/Services/FlowSessionPersister.php
source: docs/platform/architecture/adr/10-state-writer-semantics.md
summary: Flow state write semantics and optimistic retry; its D-4 is superseded by ADR-0002
source_hash: 695898dafd59d56aa6bb5ba52ffb35c8dea7932a
reviewed_at: 2026-11-25
---
# ADR-10 — State Writer Semantics

Linked source: [docs/platform/architecture/adr/10-state-writer-semantics.md](../../../docs/platform/architecture/adr/10-state-writer-semantics.md). The source owns its rules; this document only says when an agent
must read it.
