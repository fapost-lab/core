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
source_hash: 036665b2268c8270756eea2adbf03a87cac54c70
---
# Webhook Pipeline — входящее сообщение

Linked source: [docs/reference/diagrams/01-webhook-pipeline.md](../../../docs/reference/diagrams/01-webhook-pipeline.md). The source owns its rules; this document only says when an agent
must read it.
