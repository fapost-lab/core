# Node: `end`

Explicitly ends a flow.

**Type:** `end`
**Version:** 1
**Class:** `app/Domains/Flow/Handlers/EndNodeHandler.php` (category `Logic`)

## Config

```json
{
  "status": "success"
}
```

**Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `status` | enum | no | `success` / `cancelled` / `failed` (`EndStatus`). Defaults to `success` when absent. An unknown value throws `InvalidNodeConfigException` |

## Output handles

None (a terminal node).

## Behavior

The handler itself only returns `NodeExecutionResult` with status `Finished` and
`metadata['end_status']`. The **engine** does the rest (`FlowEngine::executeLoop`):

1. `FlowSessionPersister::persistEnd` merges the node's state changes and updates the session:
   - `status = ended`
   - `end_status = <config.status>`
   - `current_node_id = null`
   - `version` bumped (optimistic lock)

   There is no `ended_at` column.
2. An analytics event is recorded after commit: `flow_completed` for `success`, `flow_cancelled` for
   `cancelled`, `flow_failed` for `failed`.
3. A flow log entry is written (status `terminal`).
4. If the session has a `parent_session_id`, the engine calls
   `SubflowResumerInterface::resumeIfChild()`, which resumes the parent on the handle named after the
   status (see [08-subflow.md](08-subflow.md), "Child completion").

The Redis session lock is released by `MessageRouter` at the end of the request, not by this node.

## Validation

- `end_invalid_status`: `status` must be one of the three values.
- `end_node_has_outgoing_edge`: an `end` node must be terminal; outgoing edges are rejected.

**Not validated:** "every flow needs an `end` node". A flow may also finish without one: a node with no
outgoing edge for the handle it returned ends the session as `completed` (not `ended`, and with no
`end_status`).

---

## Related

- [../../../diagrams/04-session-state-machine.md](../../../diagrams/04-session-state-machine.md) - the end node and session statuses
- [08-subflow.md](08-subflow.md)
