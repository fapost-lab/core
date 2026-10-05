---
id: feature-flow-validation
type: feature
status: active
domains:
  - flow-builder
  - flow
paths:
  - "app/Domains/Flow/Validation/**"
  - app/Domains/Flow/Services/ValidateFlowService.php
  - app/Domains/Flow/Services/PublishFlowService.php
source: docs/reference/specs/flow-engine/06-validation.md
summary: What validates a flow at save, validate and publish time, and what each layer returns
source_hash: 94b7cd29a7dd0c2fc003804b3c946a8c0637ea43
reviewed_at: 2026-10-05
---
# Flow validation

Linked source: [docs/reference/specs/flow-engine/06-validation.md](../../../docs/reference/specs/flow-engine/06-validation.md). The source owns its rules; this document only says when an agent
must read it.
