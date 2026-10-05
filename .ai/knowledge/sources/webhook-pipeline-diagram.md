---
id: feature-webhook-pipeline-diagram
type: feature
status: active
domains:
  - flow
paths:
  - app/Domains/Flow/Routing/MessageRouter.php
  - app/Domains/Webhook/Jobs/IncomingMessageJob.php
source: docs/reference/diagrams/01-webhook-pipeline.md
summary: Sequence of an inbound webhook through the routing pipeline, lock and engine
source_hash: 6da27545dca37ba046e93b60cd54bf11eb9818e9
reviewed_at: 2026-10-05
---
# Webhook Pipeline: an incoming message

Linked source: [docs/reference/diagrams/01-webhook-pipeline.md](../../../docs/reference/diagrams/01-webhook-pipeline.md). The source owns its rules; this document only says when an agent
must read it.
