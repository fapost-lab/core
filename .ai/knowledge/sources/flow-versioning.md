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
source_hash: 1aa4947ab8fd1505cef0bbf7194f492aa504016f
reviewed_at: 2026-11-11
---
# 09 — Flow Versioning

Linked source: [docs/platform/architecture/platform/09-flow-versioning.md](../../../docs/platform/architecture/platform/09-flow-versioning.md). The source owns its rules; this document only says when an agent
must read it.
