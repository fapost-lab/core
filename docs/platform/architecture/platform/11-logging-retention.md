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
- `flow:prune-automation-runs` (`PruneAutomationRunsCommand`, `AutomationRunPruner`) deletes finished automation runs
  (`flow_sessions.run_kind = automation`) with their `flow_session_history` and `flow_logs` rows, daily at 04:30:
  succeeded runs after `flow.automation_runs.succeeded_retention_days` (7), every other terminal run after
  `flow.automation_runs.failed_retention_days` (30), both platform settings. A subflow chain goes only as a whole.
  Conversation sessions and their history are not pruned by anything yet (spec `data-lifecycle`).
- `flow:prune-versions` (`PruneFlowVersionsCommand`, `FlowVersionPruner`) deletes old published flow versions, daily at
  05:00, after the automation run prune so the versions its deleted runs held can go the same night. Off while
  `flow.versions.keep_last` is `0`; what it never deletes is in `09-flow-versioning.md`.

### Node trace in `flow_session_history`

Every node of every session — conversation, automation, subflow child — writes trace rows to `flow_session_history`
(`NodeTraceRecorder`), whatever the flow's `logging_enabled`: `node_entered` before the handler runs,
`node_completed` with status, exit, next node, duration, loop iterations and error message, or `node_failed` with the
error class and message when the session failed at the node (`step_limit` for a step limit). The rows are written
outside the step's transaction, each insert in its own savepoint; a failure is the warning `flow.trace.write_failed`
and never breaks the step.

- **Payloads.** Only an automation run's `node_completed` rows carry the node's `input` (the incoming message, the
  delay-resume flag, what the handler put in `NodeExecutionResult::$trace`) and `output` (state changes, result
  metadata, the handler's trace output; for `call`, the request and the response body). The platform setting
  `flow.trace.payloads` (default on) turns them off. A conversation's rows never carry them, because nothing deletes
  a conversation's history yet.
- **Masking and size.** Before writing, values under sensitive keys (`authorization`, `cookie`, `password`, `token`,
  `api_key`, `bearer` and the like, also as a suffix after `.`, `-`, `_`), passwords and sensitive query parameters
  in URLs are masked (`TracePayloadMasker`, which also removes literal secrets once a caller passes them — none does
  yet); then an envelope over 1 MiB has its largest `input`/`output` values replaced by `"[truncated, N bytes]"` (`TracePayloadLimiter`). Masking is a deny-list: a
  secret under an innocent key name in a response body stays visible.
- **Retention.** An automation run's trace goes with the run (`flow:prune-automation-runs`); every trace goes with
  its contact (foreign key cascade). Conversation traces are otherwise kept.
- **Visibility.** No screen reads the trace yet: the session page (`FlowSessionInspector`) filters the three event
  types out until a page gated by its own permission shows them.

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
