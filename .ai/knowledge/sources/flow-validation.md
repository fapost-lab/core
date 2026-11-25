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
source_hash: fe56122422eebd5d09fa671fb82dc6bad9df0c6f
reviewed_at: 2026-11-25
---
# Flow validation

Linked source: [docs/reference/specs/flow-engine/06-validation.md](../../../docs/reference/specs/flow-engine/06-validation.md). The source owns its rules; this document only says when an agent
must read it.
