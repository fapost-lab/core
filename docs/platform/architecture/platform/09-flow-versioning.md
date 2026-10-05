# 09 — Flow Versioning

> Originally recorded March 2026 from platform-architecture-v2; corrected against the code in October 2026.

---

## Versioning model

| Component | Description |
| --- | --- |
| **flow_definitions** | Every publish creates a new row with a full JSON snapshot of the nodes and edges. `version` increments per logical `flow_id` (`PublishFlowService`). Publishing deactivates the previous row (`is_active`), so one definition per flow is active. |
| **flow_sessions** | Pin `flow_definition_id` and `flow_version` at start. A session runs against its own snapshot to the end and is never migrated automatically. |
| **Node version field** | `{ "type": "condition", "version": 2, "config": {} }`. The engine resolves the handler by `type@version` from the in-memory registry. |
| **NodeHandlerRegistry** | In-memory, assembled at boot from service providers (`NodeHandlerRegistry`). Code is the source of truth; the database holds no handler registry. |

---

## Change rules

- **Backward-compatible change** (a new field with a default): `version` stays the same.
- **Breaking change**: `version++` for new flows; the old handler stays registered.
- **Deprecated handler**: remove one only when no active definition uses its `type@version` and no live session
  (`waiting` / `paused`) is pinned to a definition that uses it.

### Checking usage before removal

The `flow:node-usage` command (`NodeUsageStatisticsService`) reports, per tenant and per `type@version`, a static slice
(active `flow_definitions`) and a runtime slice (`flow_logs` inside the retention window), and compares them with the
registry. It is the supported tool for deciding whether a handler can go.

Limits to keep in mind: the static slice looks at active definitions only, so it does not see an older definition that a
`waiting` or `paused` session is still pinned to. Checking those sessions is a manual step; there is no table or
aggregate for it. A `flow_active_node_stats` table or view does not exist and must not be added: it would be a second
authority next to the code and the existing tables.

### Retention of old definitions

Old `flow_definitions` rows are kept: publishing never prunes them. A "keep the last N versions per flow" limit
(a once-planned default of 50) is **not implemented**; if it is ever added it must skip definitions that live sessions
are pinned to.

---

## Split authority is forbidden

The registry lives in code, not in a table. Adding a `flow_node_handlers` database table would create split authority
between code and database, which is an architectural anti-pattern.

---

## Session and snapshot

A session runs against its own `flow_definition_id` snapshot until it finishes and never moves to a newer flow version
by itself. This keeps execution deterministic: a user in the middle of a scenario does not get surprising behavior
because the flow was republished.

---

## Related

- flow engine specs in `docs/reference/specs/flow-engine/`: versioning and the handler version contract
- `docs/reference/specs/flow-engine/node-usage-statistics.md` — `flow:node-usage`
