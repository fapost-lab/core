# Node Usage Statistics

**Document:** Flow Engine node observability (static + runtime usage)
**Status:** Implemented. The open questions of the June 2026 draft extracted from the Loop
addendum are settled below.
**Context:** independent feature — not tied to loop, useful for every node type.

---

## Related

- [nodes/11-loop.md](nodes/11-loop.md) — loop node, the original context
- [nodes/README.md](nodes/README.md) — node catalogue

---

## 1. Goal

Answer, for a given tenant and for the platform as a whole:

1. **Static:** which node types are present in active flow definitions, and how often?
2. **Runtime:** how often, and how successfully, does each node type actually execute?

The consumer is **safe handler removal**: deciding whether a handler can be dropped or a
version retired.

## 2. The unit is `type@version`, not `type`

`NodeHandlerRegistry` resolves handlers by `type@version`, and old versions must stay
registered because existing definitions keep their node versions forever. A report keyed by
bare type therefore cannot answer the question that motivates it — "may I retire
`send_message@1`?" — so both slices are keyed by the pair.

Both sides carry the version already:

- `flow_definitions.nodes[].version` (optional; the engine defaults a missing value to `1`)
- `flow_logs.node_version` (`integer NOT NULL`, written by `FlowLogWriter`)

The static slice mirrors `FlowEngine::resolveNodeVersion()` exactly, so a node that omits
`version` lands on the same key as the rows the engine logged for it.

## 3. Static slice

Source: the published versions that can still run — `flow_definitions` where `is_active = true`,
plus any version a session that has not ended (`flow_sessions.status` not terminal) is still on —
expanding the `nodes` jsonb. A session keeps the version it started on, so a handler such a
version needs is still needed. `active flows` and `active nodes` count the active versions only;
`versions` counts every version read. A type that only such an older version contains is not
reported as unused. A template's published versions (`kind = template`) are left out: a template
never runs on its own, and its nodes are already in the definition of every flow that uses it.

Aggregated **in PHP**, not in SQL. Expanding jsonb in the database needs
`jsonb_array_elements` on PostgreSQL and `json_each` on SQLite, so a single statement is not
portable — and the test suite runs on SQLite while production runs on PostgreSQL, meaning the
tests would exercise a different statement than the one that ships. Active definitions are
bounded (the partial unique index `flow_definitions_active_unique` permits one per flow), so
counting them in PHP is cheap and keeps one code path on both drivers. The versions live
sessions are on add a bounded set to that; every version ever published is not read (version
retention keeps all of them by default).

### Why not the SQL view the draft proposed

The draft proposed a `node_type_usage_static` view over `jsonb_array_elements`. Rejected:

1. Not portable in one statement (above), so the view would be driver-branched and its
   PostgreSQL form would be covered only by the CI matrix.
2. A view is a schema object in every tenant schema: it must be created at provisioning,
   migrated when the node shape changes and dropped at decommission. The repository contains
   no view at all today, so this would be a new class of object to maintain.
3. It answers only within one schema. "Does *any* tenant still use X" still requires walking
   tenants, which is where the answer actually has to be assembled.

Also note the draft's `GROUP BY fd.tenant_id` is unneeded: one report is about one tenant. The
reads still filter `tenant_id` rather than rely on the schema (§5b).

## 4. Runtime slice

Source: `flow_logs`, grouped by `node_type, node_version` over a time window.

The SQL is plain aggregation — `COUNT(*)`, `SUM(CASE WHEN status = 'failed' …)`,
`MAX(created_at)` — with no JSON functions and no `AT TIME ZONE`, so it is identical on both
drivers. Partitioning is transparent to reads through the `flow_logs` root.

`failures` counts `status = 'failed'` only. `terminal` is a normal end and `conflict` is never
written by any code path today.

### Corrections to the draft's "open problem"

The draft treated runtime tracking as unsolved and proposed Redis counters to work around
gaps. Those gaps do not exist:

- **`logging_enabled` does not gate `flow_logs`.** It selects `DefaultHistoryWriter` versus
  `NoOpHistoryWriter` for the audit rows of `flow_session_history` (`HistoryWriterFactory`); the
  node trace in the same table is written whatever the flag. `FlowEngine` writes
  a flow log for every node it executes regardless of the flag.
- **Delay executions are logged.** `ResumeDelayedFlowSessionJob` → `DelayedSessionResumer` →
  `FlowEngine::runSession()` goes through the normal loop. Parking a node is logged too: a
  `Delayed`/`Waiting` result falls through to `executed`. The resumer's early returns (stale
  resume, marker mismatch, fired early) never run the engine at all, so no execution is missed.
- **Idempotency hits are logged.** Deduplication lives in delivery (`MessageSender` returns
  `DeliveryResult(duplicate: true)`); `SendMessageNodeHandler` does not branch on it and
  returns a normal `executed` result.
- **Broadcasts do not execute nodes**, so they are not a flow-log gap.

The single genuine omission is a failure of the write itself, which `writeLogSafely()`
swallows by design — flow logs are observability, not state.

**The real limit is retention.** `logs:prune-flow` drops partitions older than 30 days, so the
runtime slice cannot look further back than that. The static slice has no window, which is why
it, not the runtime one, is the authoritative answer to "is this node still in use".

## 5. Registry cross-reference

The report marks each observed pair with `NodeHandlerRegistryInterface::has($type, $version)`:

- **Used but not registered** — a node sits in an active definition with no handler for its
  version. The flow is already broken and fails when execution reaches that node.
- **Registered but unused** — reported at type granularity, because `all()` exposes only the
  latest version of each type. Across tenants this is an *intersection*: a type still used by
  one tenant is not retirable however many others have dropped it.

## 5a. Actions

An action of `ActionHandlerRegistry` (Core's or a Solution's) is used by a `call` node on the
`handler` transport whose `target` names it as written; a target built from a template is known at
run time only and counts for no action. `flow_logs` does not record the action, so its executions
are the log rows of those nodes in the window, grouped by the session's version and the node id and
matched to the node of that version. A version the static slice did not read — replaced since, its
sessions ended — is read for its nodes too, so its runs still count for their action, and an action
only such a version sent to is listed with no active flows and no versions. The report lists each action with its owner (the Solution id, null for
Core), whether it is registered now, active flows, versions and the runtime figures.

## 5b. Tenant and assistant scope

Every read filters `tenant_id` (`flow_logs` through its session). With an assistant scope — the
admin panel for staff who are not administrators — only flows whose draft belongs to those
assistants and runs of their sessions are counted. Measured on PostgreSQL with 10k published
versions (2,000 of them still runnable), 500k sessions and 3M log rows over 30 days, the whole
report reads in about 1.7 s (static 0.5 s, node runs 0.7 s, action runs 0.5 s); the screen is
read on demand, not polled, so no rollup table.

## 6. What was built

| Piece | Where |
|---|---|
| Report DTOs | `app/Domains/Flow/Statistics/NodeTypeUsage.php`, `ActionUsage.php`, `NodeUsageReport.php` |
| Aggregation | `app/Domains/Flow/Statistics/NodeUsageStatisticsService.php` |
| Contract | `app/Domains/Flow/Contracts/NodeUsageStatisticsInterface.php` |
| Console entry point | `app/Console/Commands/Flow/NodeUsageCommand.php` (`flow:node-usage`) |
| Admin panel screen | `app/Http/Controllers/Admin/NodeUsageController.php` (`admin/node-usage`), `resources/js/pages/Console/AdminNodeUsage/` |

```
php artisan flow:node-usage [--days=30] [--tenant=slug] [--type=send_message] [--json]
```

The command walks active tenants through `TenantSwitcher::runForTenant()` — both tables live
in the tenant schema, so the platform-wide answer only exists once every tenant has been
visited — and prints a per-tenant table plus a platform summary. No DDL, no new table, no new
column.

The retention boundary is stated in the output rather than left implicit: every run says which
columns are windowed and which are not, and a `--days` larger than the 30-day retention prints
a warning that executions beyond it were pruned and cannot be counted. `--json` carries the
same facts as `runtime_window_days`, `log_retention_days` and `window_exceeds_retention`,
because a machine consumer never sees the printed warning. Each usage also carries
`published_versions`, and `actions` lists, per tenant, the actions of §5a (`--type` does not
narrow it). The window is never silently
shrunk to retention: if a deployment changes the prune cutoff, a hard cap would lie.

## 7. Deliberately not built

- **`node_usage_daily` rollup.** The only way to keep history beyond the 30-day retention, and
  a new tenant table, so it is a separate architectural decision. Its value is limited while
  the static slice — which has no window — already answers the retirement question.
- **Redis counters.** Motivated by the `logging_enabled` gap that does not exist.
- **`duration_ms`.** A profiling need, not a usage-statistics one; it would be a raw-level
  column on `flow_logs`.

## 8. Related documents

- [nodes/11-loop.md](nodes/11-loop.md) — original context (Loop addendum)
- `.ai/knowledge/domains/flow/RULES.md` — handler registry and versioning rules
- `.ai/specs/flow-engine-v1x/spec.md` — the V1.x backlog
