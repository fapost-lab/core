# 11 — Logging and Retention

## Two storage levels

| Level | Description |
| --- | --- |
| **flow_logs (raw)** | One row per executed node (`FlowLogWriter`). Monthly range partitions on PostgreSQL; retention is 30 days, enforced by dropping whole partitions (instant, no table-wide DELETE). |
| **analytics_events** | Business events written through `AnalyticsWriterInterface` (`DatabaseAnalyticsWriter`): `flow_started`, `flow_completed`, `flow_cancelled`, `flow_failed` emitted by `FlowEngine`. The enum also defines `rag_query_made` and `broadcast_sent`, which no Core code emits today. |

Both tables live in the tenant schema.

### Retention jobs

- `logs:prune-flow` (`PruneFlowLogsCommand`) drops expired `flow_logs` partitions for every active tenant, daily at 03:00.
  It supports `--dry-run`.
- `logs:create-partition` (`CreateNextFlowLogPartitionCommand`) creates the current and next month partitions for every
  active tenant, on the first of each month.
- Partitioning is Postgres-only; other drivers (the SQLite test database) use a plain table.

### analytics_events has no aggregation and no pruning

Nothing aggregates `analytics_events`, and nothing prunes it. The "keep aggregated events forever" idea is only true in
the sense that the raw event rows are never deleted. There is no rollup job, no retention window and no partitioning on
this table. If volume grows, adding one is a separate decision.

---

## What is logged to `flow_logs`

`FlowEngine` writes a row for **every node it executes**, regardless of the flow's `logging_enabled` flag (that flag
gates `flow_session_history` only). That includes:

- `delay` node executions;
- nodes that resolve through an idempotency hit (they are logged like any other execution);
- failed and terminal steps.

The log write is deliberately non-fatal: the node's side effects (a sent message) have already happened, so a telemetry
failure must not fail and retry the job. The status `conflict` exists in `FlowLogStatus` but is never written.

> Partitioning is mandatory from day one. A retention DELETE on a heavy table kills PostgreSQL.

Because delay executions are logged, a large broadcast flow with delays produces many `flow_logs` rows; the 30-day
partition drop is what keeps the table bounded.

---

## Related

- `docs/reference/specs/messaging/conversation-logging.md` — conversation (transcript) storage, a separate subsystem- `docs/reference/specs/flow-engine/node-usage-statistics.md` — reads `flow_logs` for `flow:node-usage`
