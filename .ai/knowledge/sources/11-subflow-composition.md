---
id: adr-11-subflow-composition
type: adr
status: accepted
domains:
  - flow
paths:
  - "app/Domains/Flow/Subflow/**"
  - app/Domains/Flow/Handlers/SubflowNodeHandler.php
source: docs/platform/architecture/adr/11-subflow-composition.md
summary: "Subflow V1: wait-mode child session, depth 3, no recursion, latest active version"
source_hash: 37b2bebbe8d06a6f41f6f8896bbf9ee75b5ac6e3
reviewed_at: 2026-10-05
---
# ADR-11 — Subflow Composition

Linked source: [docs/platform/architecture/adr/11-subflow-composition.md](../../../docs/platform/architecture/adr/11-subflow-composition.md). The source owns its rules; this document only says when an agent
must read it.
