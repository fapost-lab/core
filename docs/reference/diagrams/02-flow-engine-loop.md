# Flow Engine: the execution loop

How the engine walks the node graph from start to the end (or to a pause for input).

The loop is `FlowEngine::executeLoop()` in `app/Domains/Flow/Services/FlowEngine.php`. It is entered
from the public API `start()`, `resume()`, `runSession()`, `resumeFromNode()` and
`resumeAfterSubflow()`.

```mermaid
flowchart TD
    START([start / resume / runSession]) --> BUDGET

    BUDGET{iterations ><br/>flow.execution.max_iterations<br/>default 100}
    BUDGET -- yes --> ERR_BUDGET([session = failed<br/>FlowExecutionLimitExceededException])
    BUDGET -- no --> CUR

    CUR{session.current_node_id<br/>set?}
    CUR -- no --> ERR_CUR([InvalidFlowGraphException<br/>::sessionHasNoCurrentNode])
    CUR -- yes --> GET_NODE

    GET_NODE{node exists<br/>in the definition?}
    GET_NODE -- no --> ERR_MISSING([InvalidFlowGraphException<br/>::missingNode])
    GET_NODE -- yes --> TYPE

    TYPE{node has a type?}
    TYPE -- no --> ERR_TYPE([InvalidFlowGraphException<br/>::nodeMissingType])
    TYPE -- yes --> GET_HANDLER

    GET_HANDLER[NodeHandlerRegistry<br/>.resolve type + version<br/>new instance in the current scope] --> HANDLER_FOUND

    HANDLER_FOUND{handler found?}
    HANDLER_FOUND -- no --> ERR_HANDLER([HandlerNotFoundException])
    HANDLER_FOUND -- yes --> CTX

    CTX[Build NodeExecutionContext<br/>state reader, ContactWriter,<br/>expression engine, idempotency key] --> HEARTBEAT

    HEARTBEAT[Heartbeat per node:<br/>typing indicator refresh +<br/>session lock TTL extend] --> LOST

    LOST{lock still ours?}
    LOST -- no --> ERR_LOCK([SessionLockLostException])
    LOST -- yes --> EXECUTE

    EXECUTE[NodeHandler::execute<br/>nodeConfig, state, context] --> THROWS

    THROWS{handler threw?}
    THROWS -- yes --> MARK_FAILED([session = failed,<br/>failed flow log, rethrow])
    THROWS -- no --> RESULT

    RESULT[NodeExecutionResult<br/>status, sourceHandle, stateChanges,<br/>logResolved, metadata,<br/>errorMessage, resumeAt] --> NEXT

    NEXT[Executed + sourceHandle:<br/>next node = edge from node<br/>with handle = sourceHandle] --> SPECIAL

    SPECIAL{special node type?}
    SPECIAL -- loop_end --> LOOP_END[next node = config.loop_node_id<br/>no edge needed]
    SPECIAL -- end --> END_NODE[Finished: end status from<br/>result.metadata, default success]
    SPECIAL -- subflow --> SUBFLOW[handler returns Waiting after<br/>SubflowStarter started the child;<br/>persist is skipped if the<br/>session version already moved]
    SPECIAL -- other --> PERSIST

    LOOP_END --> PERSIST
    SUBFLOW --> PERSIST
    END_NODE --> PERSIST_END

    PERSIST[FlowSessionPersister::persist<br/>apply stateChanges, optimistic lock,<br/>status + current_node_id] --> DELAY

    DELAY{status Delayed<br/>with resumeAt?}
    DELAY -- yes --> SCHEDULE[DelayResumeScheduler.schedule<br/>after commit, session = paused]
    DELAY -- no --> LOG
    SCHEDULE --> LOG

    PERSIST_END[FlowSessionPersister::persistEnd<br/>status = ended, end_status,<br/>current_node_id = null] --> LOG

    LOG[Write flow log, non-fatal] --> RESUME_PARENT

    RESUME_PARENT[end node: SubflowResumerInterface<br/>.resumeIfChild] --> STOP

    STOP{status Waiting, Delayed,<br/>Failed or Finished,<br/>or no next node?}
    STOP -- yes --> STOPPED([stop, session is parked or finished])
    STOP -- no --> BUDGET
```

## What the engine owns, what a handler owns

| Concern | Owner |
|---------|-------|
| Next node (edge lookup by `sourceHandle`) | Engine (`FlowGraphResolver::resolveNextNode`) |
| `loop_end` navigation back to its loop | Engine, via `config.loop_node_id` (denormalised at publish) |
| `end` status | Handler puts it in `metadata`; the persister applies it (`persistEnd`) |
| Sending messages | Handler, through `MessageSenderInterface` during `execute()`. The engine sends nothing and `NodeExecutionResult` has no messages |
| Contact mutations | Handler, through `ContactWriterInterface` |
| Session state / status / `current_node_id` | `FlowSessionPersister` |

## Status mapping (persister)

| `NodeExecutionStatus` | Session status |
|-----------------------|----------------|
| `Executed` with a next node | `active`, `current_node_id` = next node |
| `Executed` with no next node | `completed`, `current_node_id` = null |
| `Waiting` | `waiting_input` |
| `Delayed` with `resumeAt` | `paused` (plus `system.delayed.{nodeId}.resume_at`) |
| `Delayed` without `resumeAt` | `waiting_input` |
| `Failed` | `failed` |
| `Finished` (the `end` node) | `ended` with `end_status` |

## Failure modes

| Condition | Result |
|-----------|--------|
| Iteration budget exceeded (`config/flow.php`, `execution.max_iterations`, env `FLOW_MAX_ITERATIONS`, default 100) | session `failed`, `FlowExecutionLimitExceededException` |
| No current node / node not in the graph / node without type | `InvalidFlowGraphException::sessionHasNoCurrentNode()` / `missingNode()` / `nodeMissingType()` |
| No handler for `type@version` | `HandlerNotFoundException` |
| Handler throws | session `failed`, failed flow log, exception rethrown to the queue retry policy |
| Session lock lost mid-run | `SessionLockLostException`, the run is abandoned |
| Optimistic-lock conflict on save | `FlowConcurrencyException` (the orchestrator retries) |

## Optimistic lock

```
UPDATE flow_sessions
  SET state = ?, version = version + 1
  WHERE id = ? AND version = ?   -- no match -> OptimisticLockConflictException
```

## Handler contract

A handler **does not know** about the graph, only its own config and state:

```php
interface NodeHandlerInterface {
    public function execute(
        array $nodeConfig,
        array $state,
        NodeExecutionContext $context
    ): NodeExecutionResult;
}
```

It returns a `sourceHandle` (the output name), not a `nextNodeId`. The engine resolves the next node.

## Related

- [04-session-state-machine.md](04-session-state-machine.md)
- [06-subflow-lifecycle.md](06-subflow-lifecycle.md)
- `.ai/knowledge/domains/flow/OVERVIEW.md`
