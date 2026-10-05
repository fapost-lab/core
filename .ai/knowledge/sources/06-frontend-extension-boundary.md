---
id: adr-06-frontend-extension-boundary
type: adr
status: accepted
domains:
  - flow-builder
paths:
  - resources/js/builder/utils/vendorComponents.ts
source: docs/platform/architecture/adr/06-frontend-extension-boundary.md
summary: Plugins get schema-driven builder UI only; Solutions may ship Vue through vendor:publish and a rebuild
source_hash: 9c1ad671b588ce5c9259e39802155b66c0fb3551
reviewed_at: 2026-10-05
---
# ADR-06 — Frontend Extension Boundary: Plugin vs Solution

Linked source: [docs/platform/architecture/adr/06-frontend-extension-boundary.md](../../../docs/platform/architecture/adr/06-frontend-extension-boundary.md). The source owns its rules; this document only says when an agent
must read it.
