---
id: adr-06-frontend-extension-boundary
type: adr
status: accepted
domains:
  - flow-builder
paths:
  - resources/js/builder/components/editor/ConfigPanel.vue
source: docs/platform/architecture/adr/06-frontend-extension-boundary.md
summary: Plugins get schema-driven builder UI only; its Solution rules (Vue through vendor:publish and a rebuild) are superseded by adr-20261014-solution-console-ui
source_hash: 5aaa5238a5b0c0ec133e2dd8b8794b044a46c4e1
reviewed_at: 2026-10-21
---
# ADR-06 — Frontend Extension Boundary: Plugin vs Solution

Linked source: [docs/platform/architecture/adr/06-frontend-extension-boundary.md](../../../docs/platform/architecture/adr/06-frontend-extension-boundary.md). The source owns its rules; this document only says when an agent
must read it.
