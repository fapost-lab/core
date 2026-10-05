---
id: feature-flow-logging-retention
type: feature
status: proposed
domains:
  - flow
paths:
  - app/Console/Commands/PruneFlowLogsCommand.php
  - app/Console/Commands/CreateNextFlowLogPartitionCommand.php
  - "app/Domains/Flow/Logging/**"
source: docs/platform/architecture/platform/11-logging-retention.md
summary: What flow_logs and analytics_events record, partition retention jobs, and what is not pruned or aggregated
source_hash: 9ecbf1021f5d39d722a7b9d9ca9ff7fb9e87d9c2
reviewed_at: 2026-10-05
---
# 11 — Logging and Retention

Linked source: [docs/platform/architecture/platform/11-logging-retention.md](../../../docs/platform/architecture/platform/11-logging-retention.md). The source owns its rules; this document only says when an agent
must read it.
