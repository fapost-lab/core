---
id: adr-20261127-conversation-session-history-retention
type: adr
status: accepted
date: 2026-11-27
domains:
  - flow
paths:
  - "app/Domains/Flow/Retention/**"
  - app/Console/Commands/Flow/PruneSessionHistoryCommand.php
  - app/Domains/Flow/Models/FlowSessionHistoryEntry.php
summary: A finished conversation session's whole flow_session_history is deleted after the per-install flow.session_history.retention_days (default 0 = keep forever), a subflow chain only as a whole; supersedes ADR-10's per-tenant 90-day plan
---
# A finished conversation session's whole history is deleted after a per-install retention, off by default, a subflow chain only as a whole

Supersedes, in part, ADR-10 (`docs/platform/architecture/adr/10-state-writer-semantics.md`, "History Retention", which
planned a per-tenant retention with a 90-day default) and updates the premise of
`adr-20261119-node-trace-in-session-history` that nothing deletes a conversation's history. Approved by the owner at the
design gate of task `flow-session-history-retention` (spec `data-lifecycle`, phase 1).

## Context

Only automation runs' history was pruned (with the run, `AutomationRunPruner`); a conversation session's
`flow_session_history` — the node trace on every node and the opt-in `state_change`/`subflow_*` rows — grew without
bound. Conversation history already exists on running installs, so any retention on by default deletes it on the first
run after an upgrade, irreversibly. Spec `data-lifecycle` leaves "per tenant or per install" open; audit, conversation and
automation-run retention all chose per install.

## Decision

- One platform setting, `flow.session_history.retention_days` (0–3650, env default
  `FLOW_SESSION_HISTORY_RETENTION_DAYS`), for the whole installation. **0, the default, keeps the history forever.**
- The daily `flow:prune-session-history` (04:45, `withoutOverlapping`) deletes, per active tenant, the whole history of
  each conversation session (`run_kind = conversation`) of that tenant whose status is in
  `AutomationRunRetention::TERMINAL` and that finished (`updated_at`) before the cutoff. The session rows stay.
- A subflow chain loses its history only as a whole, once every member qualifies; a child is never picked alone.
- No row lock: a terminal status is never left and a finished session starts nothing; the `DELETE` repeats each
  session's own conditions.
- A tenant index `flow_sessions (updated_at, id) WHERE run_kind = 'conversation'` (PostgreSQL) serves the walk.
- Automation runs are not governed by it: their history goes with the run.

## Alternatives

- **On by default, 90 days (ADR-10):** would delete existing history on upgrade without an operator's decision.
- **Rows older than the cutoff, live sessions included:** bounds a session that waits forever, but deletes a running
  session's history and leaves a trace without its beginning.
- **A direct-parent check instead of whole chains:** a finished grandchild of a live root would lose its history and
  leave a hole in the root's run graph.
- **Per tenant:** rejected for now with the siblings; a tenant override can be added later.
- **An index on `flow_session_history(created_at)`:** the largest table involved; building it blocks writes.

## Consequences

- Until an operator sets a period, conversation history still grows; a session in `waiting_input` keeps growing even
  then.
- A conversation's trace stays without input/output (D-3 of the node-trace ADR holds).
- The daily walk visits every old finished root that still has history; a root whose history is gone is skipped, so the
  walk shrinks as it drains. A chain deeper than five levels is kept.
