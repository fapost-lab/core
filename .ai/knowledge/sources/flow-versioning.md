---
id: feature-flow-versioning
type: feature
status: active
domains:
  - flow
paths:
  - "app/Domains/Flow/Registry/**"
  - app/Domains/Flow/Services/PublishFlowService.php
  - app/Domains/Flow/Models/FlowDefinition.php
source: docs/platform/architecture/platform/09-flow-versioning.md
summary: Flow definition snapshots, session pinning to a version and rules for removing a deprecated node handler
source_hash: b61ddfab34e4c5d3ed858cb222cfedc8aa6a551a
reviewed_at: 2026-11-13
---
# 09 — Flow Versioning

Linked source: [docs/platform/architecture/platform/09-flow-versioning.md](../../../docs/platform/architecture/platform/09-flow-versioning.md). The source owns its rules; this document only says when an agent
must read it.
