# Node: `subflow`

Calls another flow and pauses the parent until the child finishes.

**Type:** `subflow`
**Version:** 1
**Class:** `app/Domains/Flow/Handlers/SubflowNodeHandler.php` (category `Logic`)
**Idempotent:** the handler returns `Waiting` without starting a second child while one is live

## Config

```json
{
  "flow_id": "01HQ_collect_personal_data",
  "timeout": "PT24H",
  "input_mapping": {
    "user_id": "{{flow.user_id}}",
    "greeting": "Hello, {{contact.name}}"
  },
  "output_mapping": {
    "price": "calculated_price"
  }
}
```

**Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `flow_id` | string | yes | Logical flow id (**not** a flow definition id), a literal. Resolved to the **latest active** definition at runtime, or to `flow_version` when that is set |
| `flow_version` | integer | no | Pins the node to one published version of the child (`flow_definitions.version`), active or superseded. Absent or empty runs the latest active definition |
| `timeout` | string | yes | ISO 8601 duration (default `PT24H`). When it passes, the child is force-failed |
| `input_mapping` | object | no | Child session variable name → expression over the parent's state. Sets the child's `flow.<name>` when it starts |
| `output_mapping` | object | no | Parent session variable name → child session variable name. Copies the child's `flow.<name>` into the parent's on `success` |

Both mappings are optional and were reserved by ADR-11 for V1.1; adding them did not change the node's
version (an optional field with a no-op default). Without them the parent passes nothing and receives
nothing back. Names on both sides are session variable names without the `flow.` prefix, checked like any
session variable (`Variable`: an identifier, not a reserved name). Contact data (`contact.*`) is shared by
parent and child anyway, so a mapping never targets it.

`flow_version` is a third optional field, added to `subflow@1` the same way. A pinned node keeps running the
chosen version of the child when the child is republished (ADR-11 listed pinning as a possible V1.x
addition). `SubflowPin` (`app/Domains/Flow/Subflow/`) reads it: a positive whole number, or empty for no pin.
The builder offers it as a number field ("Pinned version"), empty meaning the active version.

`SubflowMapping` (`app/Domains/Flow/Subflow/`) reads both fields.

## Output handles

- `success`: the child ended through an `end` node with status `success`, or ran off its graph without an
  `end` node
- `cancelled`: the child ended with status `cancelled`
- `failed`: the child ended with status `failed`, failed (iteration limit, a handler exception or a node
  reporting failure), timed out, or the target flow is not active

The mapping from the child's `end_status` to the handle is 1:1 (`DefaultSubflowResumer::handleFor`).
A child that stops without an `end` node still hands control back: the engine calls `resumeIfChild` with
`success` when it runs off its graph and `failed` when it fails, so a parent never waits in `paused_subflow`
without a living child. When a failed child hands back, the engine records the failure and returns instead of
rethrowing; what the parent's own resumed run throws propagates as the parent's own.

## V1 limits

- **Parameters only through the mappings:** the child sees the parent's session variables only as the
  node's `input_mapping` passes them, and the parent receives only what `output_mapping` names, on `success`.
- **Outputs go to session variables:** an `output_mapping` target is a parent `flow.*` variable, never a
  contact variable (the hand-back has no `ContactWriter`).
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
4. Evaluate `input_mapping` against the parent's state: a value that is exactly one placeholder
   (`{{flow.items}}`) is read through the state reader and keeps its type (an array stays an array); any
   other value is rendered as a template string (a literal stays as it is). A mapping that is not an
   object, an invalid name or a non-string value throws `InvalidNodeConfigException` before any child is
   created.
5. `SubflowStarterService::start()` (see below), with the evaluated values. If the target flow has no
   active definition, the node returns `Failed` with `error_type = subflow_target_inactive`; if it is pinned
   to a version that does not exist, `Failed` with `error_type = subflow_pinned_version_missing`.
6. Return `Waiting` with metadata `{flow_id, child_id, timeout}` (plus `flow_version` when pinned).

`SubflowStarterService::start()` (`app/Domains/Flow/Subflow/`):

1. Resolve `flow_id` to the latest active `flow_definition`, or, when the node has a `flow_version`, to that
   version of the flow (`findRunnableByFlowIdAndVersion`: a flow, never a template, active or not).
2. In one transaction: pause the parent first (`status = paused_subflow`) and, when the parent's
   `expires_at` is empty or earlier than the child's, set it to the child's `expires_at + 1h`; then
   create the child session with:
   - `tenant_id`, `contact_id`, `assistant_id` inherited from the parent (the assistant is immutable),
   - `parent_session_id` = the parent id,
   - `parent_resume_node_id` = the subflow node id,
   - `flow_definition_id` / `flow_version` of the resolved definition,
   - `current_node_id` = the derived entry node, `state` = the mapped input as `flow.<name>` (empty without
     one), `status = active`,
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
4. On the `success` handle only, compute the outputs: find the subflow node (`parent_resume_node_id`) in
   the parent's own definition snapshot and, while the parent still stands on that node, read each
   `output_mapping` source from the child's final `flow.*` (a variable the child never set gives `null`, as
   a `call` node's `result_mapping` does; an entry with an invalid name is skipped). `cancelled`, `failed`,
   a timeout and an orphan hand-back pass no outputs.
5. Call `FlowEngine::resumeAfterSubflow($parent, $handle, $outputs)`: resolve the next node from the parent's
   current node (still the subflow node) and the handle, set the parent `active` (or `completed` when
   there is no edge for that handle), write the outputs into the parent's `flow.*` in that same save
   (each replaces the variable's previous value) and run the parent's loop, synchronously. A parent that completes
   this way hands its own parent `success` when it is itself a child. A lost optimistic lock on the parent
   is retried once, only while the parent still waits on the same subflow node.

A missing or not-paused parent is logged and never throws: the child is already terminal. A global
command that ends a session (`/reset`, `/cancel`, a start-flow command) also ends every `paused_subflow`
ancestor, so the chain never outlives its leaf.

## Timeout handling

The command `flow:sweep-subflow-timeouts` (`SweepSubflowTimeoutsCommand`) runs every minute
(`routes/console.php`, `withoutOverlapping`) and calls `SubflowTimeoutSweeper::sweep()` for each
active tenant. For every parent in `paused_subflow` whose `expires_at` has passed, under the parent's
session lock and after re-reading it:

- **A live child exists** (`active`, `waiting_input`, `paused` on a `delay`, or `paused_subflow` on a
  nested subflow): force-end the child's live descendants, then the child itself, as `ended` with
  `end_status = failed` (a nested chain unwinds bottom-up), then `resumeIfChild($child, 'failed')`, so the
  parent continues on `failed`.
- **No live child (an orphan parent):** mark the parent `expired` with `current_node_id = null`, and, when
  it is itself a child, hand its own parent `failed`. This
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
- `subflow_invalid_flow_version`: `flow_version` is set but is not a positive whole number.
- `subflow_pinned_version_missing`: the target flow has no published version `flow_version` (looked up among
  its flow definitions, so another flow's version number never satisfies the pin).
- `subflow_invalid_input_mapping`, `subflow_invalid_output_mapping`: the field is not an object of name to
  value.
- `subflow_invalid_input_name`, `subflow_invalid_output_name`: a key is not a valid session variable name
  (an identifier, not reserved; no `flow.` prefix).
- `subflow_invalid_input_value`: an input value is not a string; `subflow_invalid_output_source`: an output
  value is not a valid session variable name.

The mapping checks come from `SubflowMappingValidator`, which `SubflowNodeHandler` exposes through
`NodeConfigValidatorInterface`. They look only at names: whether the child flow ever sets a mapped
variable is not checked, since its latest active definition is resolved only when it starts.
- The call-graph and cross-assistant codes above.

A missing target flow is not a publish error: an unknown callee has no edges, and a callee with no active
definition surfaces at runtime as `subflow_target_inactive`, which fails the node result (the parent session fails; the `failed` handle is for a child that ended failed).

Save-draft does not validate; validate and publish do (see [../06-validation.md](../06-validation.md)).

---

## Related

- [../../../diagrams/06-subflow-lifecycle.md](../../../diagrams/06-subflow-lifecycle.md) - lifecycle diagram
- [07-emit-event.md](07-emit-event.md) - the fire-and-forget alternative
