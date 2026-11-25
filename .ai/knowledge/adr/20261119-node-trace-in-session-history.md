---
id: adr-20261119-node-trace-in-session-history
type: adr
status: accepted
date: 2026-11-19
domains:
  - flow
paths:
  - "app/Domains/Flow/Trace/**"
  - "app/Domains/Flow/History/**"
  - app/Domains/Flow/Services/FlowEngine.php
  - app/Domains/Flow/Services/FlowSessionInspector.php
  - app/Http/Controllers/Console/FlowSessionController.php
summary: The engine writes a masked, size-limited node trace (node_entered/completed/failed) to flow_session_history for every session whatever logging_enabled; only automation runs carry input/output, behind flow.trace.payloads; supersedes ADR-10's history-logging gate
reviewed_at: 2026-11-25
---
# Every node of every session writes a masked, size-limited trace to flow_session_history, whatever logging_enabled

Supersedes, in part, ADR-10 (`docs/platform/architecture/adr/10-state-writer-semantics.md`, "History Logging"):
node entry, exit and error events are no longer gated by `logging_enabled`. Approved by the owner at the design gate
of task `flow-node-trace` (decisions D-1, D-3, D-5, D-6).

## Context

ADR-10 made `flow_session_history` opt-in per flow: with `logging_enabled = false` no row is written, and node
lifecycle events were planned behind the same flag. The `flow-automations` spec needs a run trace for every run — a
graph of a run with each node's input and output, a `call` node's request and response body — so a failed run can be
understood after the fact without having switched anything on beforehand. Such a trace holds secrets and personal
data by nature: headers, tokens in URLs, response bodies, a contact's messages.

## Decision

- `FlowEngine` calls `NodeTraceRecorder` for every node of every session (conversation, automation, subflow child):
  `node_entered` before the handler, then `node_completed` (status, handle, next node, duration, loop iterations,
  error message) or `node_failed` (error class and message; `step_limit` for a step limit). The flag keeps gating the
  audit rows (`state_change`, `subflow_*`) as ADR-10 says.
- Only an automation run's `node_completed` rows carry `input`/`output`, and only while the platform setting
  `flow.trace.payloads` is on. A conversation's rows carry none: nothing deletes a conversation's history yet, and a
  conversation is a person's data (D-3). A handler adds its own data through `NodeExecutionResult::$trace`
  (Foundation 0.44, experimental), which goes to the trace and nowhere else; in v1 only `call` does (D-6).
- Payloads are masked (`TracePayloadMasker`: sensitive key names, also inside a string holding a JSON document such
  as a raw request body; URL passwords and query parameters, also `query.<name>` call parameters and the pairs of a
  form-urlencoded body; literal secrets) and then limited (`TracePayloadLimiter`: 1 MiB a row, largest values replaced by
  `"[truncated, N bytes]"`) before they are written.
- Rows are written outside the step's transaction, each insert in a savepoint of its own; a failure is the warning
  `flow.trace.write_failed` and never fails the step.
- No screen reads the trace until a page gated by its own permission (`ViewRunPayloads`) exists:
  `FlowSessionInspector` filters the three event types out.

## Alternatives

- **Write the trace into the partitioned `flow_logs`** (fewer inserts a node, conversation retention for free):
  rejected at the gate (D-1) in favour of the table the spec names; trace rows can still be removed by `event_type`
  without DDL.
- **Keep the trace behind `logging_enabled`:** the trace would be missing exactly when a run has to be explained.
- **Prune conversation traces after 30 days (G1):** rejected at the gate; a conversation's trace simply carries no
  payloads until the `data-lifecycle` spec decides conversation retention.

## Consequences

- Two more inserts a node, plus masking and encoding up to 1 MiB; an automation segment fits fewer steps (risk R-1).
  `flow.trace.payloads` is the lever.
- Masking is a deny-list: a secret under an innocent key name, or in a response body without a sensitive key, stays
  visible unless it is passed as a literal secret (risk R-2). Credentials will pass their values as literals.
- The trace keeps masked `call` request parameters and response bodies in Postgres, unlike the call ledger, which
  never stores them (`adr-20261118-call-outbound-idempotency-strategy`).
- An automation run's trace goes with the run (`AutomationRunPruner`); every trace goes with its contact (foreign key
  cascade). A contactless run's trace is bounded only by automation retention (R-4).
- Code that reads `flow_session_history` must decide whether it may see the trace; today only the session page does,
  and it filters it out.

## Amendment 2026-11-20: the run graph reads statuses

Recorded by the agent of task `flow-run-graph-page` on an unattended run, from the `flow-automations` spec the owner
approved ("`ViewRunPayloads` (full node data in a trace; `ViewFlowSessions` shows the graph and statuses only)"); it
awaits the owner's review in that task's pull request. The text above is kept as accepted; read its "no screen reads the
trace" bullet and its last consequence as narrowed here.

- The run graph of a session (`RunGraphReader`, behind `ViewFlowSessions`) reads the trace for statuses only: it
  selects a whitelist of envelope fields by JSON path — type, status, handle, next node, duration, loop iterations,
  error class — and never `input`, `output` or the error message.
- Those stay unread by any screen until a page gated by `ViewRunPayloads` exists. The session page's history still
  filters the trace out.

## Amendment 2026-11-25: a visit's data behind `ViewRunPayloads`

Recorded by the agent of task `flow-run-graph-payloads`; the owner approved its design at the gate ("I accept your
decisions": every recommended option). It awaits the owner's review in that task's pull request. Read the 2026-11-20
amendment's second bullet as fulfilled here.

- The run graph shows a node visit's `input`, `output` and error message to a holder of `ViewRunPayloads` (plus
  `ViewFlowSessions`), one visit a request, through its own endpoint and reader (`RunPayloadReader`,
  `FlowSessionController::payload()`): a trace row of the run's session or of a subflow child, in the current tenant;
  anything else is a 404. The response is one row, so it is bounded by the row limit, and is not cached.
- The status path does not change: `RunGraphReader` keeps its whitelist, and the page's props and the visits list
  carry no payload, even for a holder. A visit carries its row's `id`, and for a holder a `hasData` flag computed in
  SQL without reading the values.
- A conversation's visits show their masked error message to a holder: D-3 excluded only input and output.
- Viewing is not audited yet; the `audit-log` spec owns that.
- Rejected: sending payloads inline in the visits list for holders (up to about 100 MiB a response, and the status
  reader would have to learn payload fields).
