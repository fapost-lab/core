# Node: `subflow`

Calls another flow and pauses the parent until the child finishes.

**Type:** `subflow`
**Version:** 1
**Class:** `app/Domains/Flow/Handlers/SubflowNodeHandler.php` (category `Logic`)
**Idempotent:** the handler returns `Waiting` without starting a second child while one is live

## Config (V1)

```json
{
  "flow_id": "01HQ_collect_personal_data",
  "timeout": "PT24H"
}
```

**Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `flow_id` | string | yes | Logical flow id (**not** a flow definition id), a literal. Resolved to the **latest active** definition at runtime |
| `timeout` | string | yes | ISO 8601 duration (default `PT24H`). When it passes, the child is force-failed |

Pinning a specific version is not supported. `input_mapping` / `output_mapping` do not exist: the node
ignores any such keys. Coordination between parent and child goes through `contact.*` or module data.

## Output handles

- `success`: the child ended through an `end` node with status `success`
- `cancelled`: the child ended with status `cancelled`
- `failed`: the child ended with status `failed`, or timed out, or the target flow is not active

The mapping from the child's `end_status` to the handle is 1:1 (`DefaultSubflowResumer::handleFor`).

## V1 limits

- **No parameters:** the parent passes nothing to the child and receives nothing back.
- **One child at a time.**
- **Depth at most 3:** a chain `A -> B -> C` is allowed (depth counts flows, caller included);
  `A -> B -> C -> D` is rejected (`CallGraphValidator::MAX_DEPTH`).
- **No direct or indirect recursion:** `A -> A` (`subflow_direct_recursion`) and `A -> B -> A`
  (`subflow_indirect_recursion`) are rejected.
- **Wait mode only:** fire-and-forget is `emit_event`.
- **No cross-assistant calls:** the child inherits the parent's `assistant_id`; `PublishFlowService`
  rejects a callee owned by another assistant (`subflow_cross_assistant`).

## Behavior

`SubflowNodeHandler::execute()`:

1. Validate config: an empty `flow_id` or an invalid ISO 8601 `timeout` throws `InvalidNodeConfigException`.
2. Load the parent session. If it is missing, return `Failed` with `error_type = subflow_parent_missing`.
3. If the parent already has a live child (a child session not in a terminal status), return `Waiting`
   with `reason = child_already_running`. No second child is created.
4. `SubflowStarterService::start()` (see below). If the target flow has no active definition, the node
   returns `Failed` with `error_type = subflow_target_inactive`.
5. Return `Waiting` with metadata `{flow_id, child_id, timeout}`.

`SubflowStarterService::start()` (`app/Domains/Flow/Subflow/`):

1. Resolve `flow_id` to the latest active `flow_definition`.
2. In one transaction: pause the parent first (`status = paused_subflow`) and, when the parent's
   `expires_at` is empty or earlier than the child's, set it to the child's `expires_at + 1h`; then
   create the child session with:
   - `tenant_id`, `contact_id`, `assistant_id` inherited from the parent (the assistant is immutable),
   - `parent_session_id` = the parent id,
   - `parent_resume_node_id` = the subflow node id,
   - `flow_definition_id` / `flow_version` of the resolved definition,
   - `current_node_id` = the derived entry node, `state = []`, `status = active`,
   - `expires_at` = now + `timeout`.
3. Record a `SubflowStarted` history event on the parent (when the parent flow has history logging on).
4. Run the child synchronously: `FlowEngine::runSession($child)`.

Because the starter has already moved the parent (version bump), the engine skips persisting the
handler's stale `Waiting` result for the parent (see the loop diagram).

The session lock stays on the same `(tenant, contact, assistant)` key throughout; there is no physical
hand-over.

## Routing invariant

An inbound message looks up the contact's active session with `findActiveForContact()`, which returns
`active`, `waiting_input` and `paused` sessions. A `paused_subflow` parent is not among them, so the
message goes to the live child.

## Child completion

The `end` node handler only returns `Finished` with `end_status` in metadata. The **engine** persists
the end (`FlowSessionPersister::persistEnd`: `status = ended`, `end_status`, `current_node_id = null`;
there is no `ended_at` column) and then calls `SubflowResumerInterface::resumeIfChild($child, $endStatus)`.

`DefaultSubflowResumer::resumeIfChild()`:

1. Do nothing for a top-level session (no `parent_session_id`).
2. Load the parent. If it is missing, log `flow.subflow.resume.parent_missing` and stop. If it is not
   `paused_subflow`, log `parent_not_paused` and continue anyway.
3. Record a `SubflowReturned` history event on the parent.
4. Call `FlowEngine::resumeAfterSubflow($parent, $handle)`: resolve the next node from the parent's
   current node (still the subflow node) and the handle, set the parent `active` (or `completed` when
   there is no edge for that handle) and run the parent's loop, synchronously.

An inconsistent state never throws: the child is already terminal.

## Timeout handling

The command `flow:sweep-subflow-timeouts` (`SweepSubflowTimeoutsCommand`) runs every minute
(`routes/console.php`, `withoutOverlapping`) and calls `SubflowTimeoutSweeper::sweep()` for each
active tenant. For every parent in `paused_subflow` whose `expires_at` has passed, under the parent's
session lock and after re-reading it:

- **A live child exists** (`active` or `waiting_input`): force-end the child as `ended` with
  `end_status = failed`, then `resumeIfChild($child, 'failed')`, so the parent continues on `failed`.
- **No live child (an orphan parent):** mark the parent `expired` with `current_node_id = null`. This
  should not happen because the starter extends the parent's expiry past the child's, but the sweeper
  recovers if the invariant breaks.
- A parent whose lock is busy is skipped and retried on the next sweep.

## Cycle prevention

Referential integrity uses a reverse index, the `flow_callgraph_edges` table
(`caller_flow_id`, `callee_flow_id`, `caller_definition_id`), through `CallGraphRepository`.

- `PublishFlowService` replaces the caller's edges inside the publish transaction
  (`replaceForCallerDefinition`) after extracting every `subflow.flow_id` from the nodes.
- `ValidateFlowService::validateSubflowCallGraph()` runs `CallGraphValidator::validate(flowId, callees)`
  when a flow id is known: direct recursion, indirect recursion (a forward BFS from each callee back to
  the caller) and the depth of the longest forward chain from the caller.
- Cross-assistant ownership is checked separately by `PublishFlowService` through `flow_drafts`.

Violation codes: `subflow_direct_recursion`, `subflow_indirect_recursion`, `subflow_depth_exceeded`,
`subflow_cross_assistant`.

Not built: validating the **callers** of a flow when that flow is republished (a reverse BFS, "cascade
refuse"). Only the flow being validated is checked, so republishing a callee that deepens an existing
caller's chain is not detected at that moment.

### Active sessions

Running sessions are unaffected: each runs on its own `flow_definition_id` snapshot, and older
definitions stay in `flow_definitions`. Validation concerns only sessions that start later.

## Validation

- `subflow_missing_flow_id`, `subflow_template_in_flow_id`: `flow_id` must be a non-empty literal.
- `subflow_invalid_timeout`: `timeout` must be an ISO 8601 duration.
- The call-graph and cross-assistant codes above.

A missing target flow is not a publish error: an unknown callee has no edges, and a callee with no active
definition surfaces at runtime as `subflow_target_inactive` (the `failed` handle).

Save-draft does not validate; validate and publish do (see [../06-validation.md](../06-validation.md)).

---

## Related

- [../../../diagrams/06-subflow-lifecycle.md](../../../diagrams/06-subflow-lifecycle.md) - lifecycle diagram
- [07-emit-event.md](07-emit-event.md) - the fire-and-forget alternative
