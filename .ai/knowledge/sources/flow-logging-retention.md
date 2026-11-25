---
id: feature-flow-logging-retention
type: feature
status: active
domains:
  - flow
paths:
  - app/Console/Commands/PruneFlowLogsCommand.php
  - app/Console/Commands/CreateNextFlowLogPartitionCommand.php
  - "app/Domains/Flow/Logging/**"
source: docs/platform/architecture/platform/11-logging-retention.md
summary: What flow_logs and analytics_events record, partition retention jobs, and what is not pruned or aggregated
source_hash: 7d81d65b0404e12c651a1ae89d0ca6decd762657
reviewed_at: 2026-11-25
---
# 11 — Logging and Retention

Linked source: [docs/platform/architecture/platform/11-logging-retention.md](../../../docs/platform/architecture/platform/11-logging-retention.md). The source owns its rules; this document only says when an agent
must read it.
