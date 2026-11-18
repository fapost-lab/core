# Subflow Lifecycle: starting a nested flow and returning control

The full cycle of a `subflow` node: from the contact's message to a resumed parent, including how
inbound messages reach the child session.

```mermaid
sequenceDiagram
    actor U as Contact
    participant MR as MessageRouter
    participant FO as FlowOrchestrator
    participant FE as FlowEngine
    participant SH as SubflowNodeHandler
    participant ST as SubflowStarterService
    participant DB as flow_sessions
    participant SR as DefaultSubflowResumer

    Note over U,SR: Phase 1: the parent flow reaches the subflow node

    U->>MR: inbound message
    MR->>FO: handle(contact, message, assistantId)
    FO->>FE: resume(parentSession, message)

    FE->>SH: execute(subflowConfig, state, context)
    SH->>SH: evaluate input_mapping against the parent's state
    SH->>ST: start(parent, flow_id, timeout, subflowNodeId, input)
    ST->>DB: UPDATE parent: status = paused_subflow<br/>(expires_at extended past the child's)
    ST->>DB: CREATE child FlowSession<br/>parent_session_id = parent.id<br/>parent_resume_node_id = subflow node id<br/>flow_definition_id = latest active of flow_id (or the pinned flow_version)<br/>state = flow.* from input_mapping, status = active
    ST->>FE: runSession(child) (synchronously)
    FE->>FE: child runs to its first wait point<br/>(or straight to its end node)
    ST-->>SH: child session
    SH-->>FE: NodeExecutionResult::Waiting<br/>metadata {flow_id, child_id, timeout}

    Note over FE: The parent was already moved by the starter,<br/>so the engine skips persisting this Waiting result

    Note over U,SR: Phase 2: interaction inside the child

    U->>MR: message (reply to an input in the child)
    MR->>MR: findActiveForContact: parent is paused_subflow<br/>and not returned; the live child is
    MR->>FO: handle(...)
    FO->>FE: resume(childSession, message)
    FE-->>FO: child continues / waits for input

    Note over U,SR: Phase 3: the child ends (end node)

    FE->>FE: child's end node: persistEnd<br/>status = ended, end_status = success|cancelled|failed
    FE->>SR: resumeIfChild(child, endStatus)
    SR->>DB: LOAD parent by parent_session_id
    SR->>SR: on success: output_mapping over the child's state
    SR->>FE: resumeAfterSubflow(parent, handle = end_status, outputs)
    FE->>DB: parent: current_node_id = edge(subflow node, handle).to<br/>status = active (or completed if no edge)<br/>state.flow += outputs
    FE->>FE: executeLoop(parent): parent continues<br/>synchronously, inside the same tick
    FE-->>U: reply from the parent flow
```

## Key classes

| Class | Path | Role |
|-------|------|------|
| `SubflowNodeHandler` | `app/Domains/Flow/Handlers/SubflowNodeHandler.php` | Validates config, calls the starter, returns `Waiting`. Failures: `subflow_parent_missing`, `subflow_target_inactive`, `subflow_pinned_version_missing` (failed results) |
| `SubflowStarterService` | `app/Domains/Flow/Subflow/SubflowStarterService.php` | Pauses the parent, creates the child, drives it with `runSession()` |
| `SubflowResumerInterface` / `DefaultSubflowResumer` | `app/Domains/Flow/Subflow/` | `resumeIfChild(child, endStatus)`: maps `end_status` to a handle, records history, calls `FlowEngine::resumeAfterSubflow()` |
| `NoOpSubflowResumer` | `app/Domains/Flow/Subflow/` | Null implementation |
| `SubflowTimeoutSweeper` | `app/Domains/Flow/Subflow/SubflowTimeoutSweeper.php` | Timeout and orphan recovery, run by the `flow:sweep-subflow-timeouts` command every minute |
| `CallGraphValidator` / `CallGraphRepository` | `app/Domains/Flow/Subflow/` | Cycle and depth checks at validation / publish time |
| `FlowEngine` | `app/Domains/Flow/Services/FlowEngine.php` | The engine, not the end handler, calls `resumeIfChild` after persisting an end node |

## Important invariants

- **Resume is done by the engine.** `EndNodeHandler` only returns `Finished` with the end status in
  `metadata`; after `persistEnd` the engine calls `SubflowResumerInterface::resumeIfChild()`. The
  end handler never touches the parent.
- **The child runs synchronously.** `SubflowStarterService` calls `FlowEngine::runSession($child)`,
  so one inbound message can carry parent, child, child end and parent resume in a single run.
- **Routing:** while a child is live the contact's messages go to the child, because a
  `paused_subflow` parent is not returned by `findActiveForContact()`.
- **`flow_definition_id` is a snapshot:** the child starts on the latest active definition of
  `flow_id` (or on the node's pinned `flow_version`) and stays on it.
- **`parent_resume_node_id`** is the id of the parent's subflow node (`$subflowNodeId` in
  `SubflowStarterService`). It is stored on the child and shown in the admin panel; the resumer
  continues from the parent's own `current_node_id`, which still is the subflow node.
- **Depth:** at most 3 levels (`A -> B -> C -> D` is rejected), checked by `CallGraphValidator` at
  validate / publish time, not at runtime. A cross-assistant subflow is rejected by
  `PublishFlowService` (`subflow_cross_assistant`).
- **End status to handle:** the parent continues on the handle named after the child's `end_status`:
  `success`, `cancelled` or `failed` (`SubflowNodeHandler::HANDLE_*`).
- **A child that stops without its own `end` node** still hands control back, so a parent is never left in
  `paused_subflow` without a living child: running off the graph takes `success`; an exhausted iteration
  budget, a handler exception or a node reporting failure takes `failed` (the engine records the failure and
  returns instead of rethrowing). `/reset`, `/cancel`, tenant terminate commands and a start-flow command end the whole `paused_subflow`
  chain, not only the leaf. A lost optimistic lock on the parent while resuming is retried once.
- **Timeouts:** `config.timeout` is an ISO 8601 duration (default `PT24H`). When the parent's
  `expires_at` passes, `SubflowTimeoutSweeper` force-ends a live child as `failed` and resumes the
  parent on `failed`; a parent with no live child is marked `expired` (and, if it is itself a child, hands
  its own parent `failed`). A child counts as live while `active`, `waiting_input`, `paused` (a `delay`) or
  `paused_subflow`; a nested chain is failed bottom-up. Each parent is handled under
  its session lock; a busy lock is skipped until the next sweep.
- **Input and output mapping:** only what the node maps crosses between the sessions. The handler
  evaluates `input_mapping` against the parent's state and the starter creates the child with those
  `flow.*` variables (an empty `state` without one). On the `success` handle only, the resumer reads
  `output_mapping` from the parent's own definition and the child's final state and passes the values to
  `resumeAfterSubflow()`, which writes them in the same save that advances the parent.

## Related

- [04-session-state-machine.md](04-session-state-machine.md) (the `paused_subflow` status)
- [02-flow-engine-loop.md](02-flow-engine-loop.md)
- [../specs/flow-engine/nodes/08-subflow.md](../specs/flow-engine/nodes/08-subflow.md)
