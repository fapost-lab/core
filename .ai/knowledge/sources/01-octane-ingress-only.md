---
id: adr-01-octane-ingress-only
type: adr
status: accepted
domains: []
paths:
  - "app/Domains/Webhook/**"
  - "gateway/**"
source: docs/platform/architecture/adr/01-octane-ingress-only.md
summary: Cancelled Octane decision; still the source of the long-lived worker safety constraints
source_hash: 780319d5f0f1f6f755d58cc422ccec616f20a228
reviewed_at: 2026-10-05
---
# ADR-01 — Octane: ingress-only

Linked source: [docs/platform/architecture/adr/01-octane-ingress-only.md](../../../docs/platform/architecture/adr/01-octane-ingress-only.md). The source owns its rules; this document only says when an agent
must read it.
