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
source_hash: ceb69eefaac339f747c33db95097cef861657775
reviewed_at: 2026-10-14
---
# ADR-06 — Frontend Extension Boundary: Plugin vs Solution

Linked source: [docs/platform/architecture/adr/06-frontend-extension-boundary.md](../../../docs/platform/architecture/adr/06-frontend-extension-boundary.md). The source owns its rules; this document only says when an agent
must read it.
