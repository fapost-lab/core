# FlowSession: the session lifecycle

A statechart of `FlowSession` states from creation to the end, including waiting for input, nested
subflows and abnormal exits. Statuses are the `FlowSessionStatus` enum
(`app/Domains/Flow/Enums/FlowSessionStatus.php`).

```mermaid
stateDiagram-v2
    [*] --> active : FlowEngine::start creates the session<br/>flow_definition_id is pinned

    active --> waiting_input : handler returned waiting()<br/>or delayed() without resumeAt:<br/>waits for the next message

    waiting_input --> active : contact replied<br/>(MessageRouter routes into the session)

    active --> paused : handler returned delayed(resumeAt)<br/>FlowSessionPersister writes<br/>system.delayed.{node}.resume_at<br/>and the engine schedules the wake-up

    paused --> active : resume_at reached:<br/>DelayedSessionResumer (job, or<br/>MessageRouter inline under the lock)<br/>re-runs the node, resumedAfterDelay=true

    paused --> terminated_by_user : GlobalCommandExecutor<br/>(/reset)

    waiting_input --> ended : only through authored edges:<br/>a timeout / no_response / invalid handle<br/>wired to an end node

    waiting_input --> cancelled : persistent button from another branch<br/>(FlowOrchestrator cancels the<br/>current session)

    active --> paused_subflow : SubflowStarterService<br/>child session created,<br/>parent_session_id set on the child

    paused_subflow --> active : child ended: DefaultSubflowResumer<br/>-> FlowEngine::resumeAfterSubflow<br/>parent continues on the handle<br/>= child end_status

    paused_subflow --> completed : child ended, parent has no<br/>edge for that handle

    paused_subflow --> ended : child ended, parent continues<br/>and reaches its own end node

    paused_subflow --> expired : SubflowTimeoutSweeper:<br/>orphan parent (no live child)<br/>and expires_at passed

    active --> completed : Executed with no next node,<br/>or a non-end node returned finished()

    active --> ended : end node executed<br/>(end_status = success / cancelled / failed)

    active --> failed : handler returned failed(),<br/>handler threw, iteration budget exceeded

    waiting_input --> failed : handler threw while handling the reply

    active --> terminated_by_user : GlobalCommandExecutor (/reset)

    waiting_input --> terminated_by_user : GlobalCommandExecutor (/reset)

    ended --> [*]
    completed --> [*]
    failed --> [*]
    expired --> [*]
    cancelled --> [*]
    terminated_by_user --> [*]
```

## Notes and caveats

- **`pending` is never assigned.** The enum has it and `SessionStateRouter` treats it as busy, but
  every session is created directly as `active` (`FlowEngine::start`, `resumeFromNode`,
  `SubflowStarterService`).
- **`active -> paused` needs `delayed(resumeAt)`, and no built-in node returns it.** The `delay` node
  parks as `waiting_input` (it returns `waiting()` and schedules its own resume job). `paused` is
  reachable only by a vendor handler that returns `NodeExecutionResult::delayed(resumeAt: ...)`.
  A plain `delayed()` parks as `waiting_input`, like `waiting()`.
- **`findActiveForContact()` returns `active`, `waiting_input` and `paused`.** A message that arrives
  before `resume_at` gets a "busy" reply and is not kept for the node. After `resume_at`,
  `MessageRouter` first wakes the node inline under the lock it already holds
  (`DelayedSessionResumer::wakeIfDue()`), then routes the same message by the new state.
- **`waiting_input -> ended` has no built-in timeout.** A `send_message` timeout or an `input`
  `no_response` / `invalid` handle only moves on if the author wired an edge from it, typically to
  an `end` node. A `waiting_input` session otherwise stays parked.
- **`cancelled`** is set by `FlowSessionRepository::cancel()` when a persistent-button press starts
  its branch: the contact's current session is cancelled so that two are never active. `/reset`
  sets `terminated_by_user` (and clears `current_node_id`), not `cancelled`.
- **Parent of a subflow is `paused_subflow`.** Such a parent is not returned by
  `findActiveForContact()`; the live child (`active` / `waiting_input`) is, so the contact's
  messages land in the child through `ResumeWaiting`. `SessionStateRouter` maps `paused_subflow` to
  `DropBusy` only as a defensive answer, so a parent that leaks through never starts a parallel session.
- **`completed` vs `ended`.** `ended` (with `end_status`) is written only by `persistEnd` for an
  `end` node. `completed` is the plain "ran out of graph" outcome.
- `expired` is set only by `SubflowTimeoutSweeper` for orphaned parents. A parent whose child is
  still alive past `expires_at` is not expired: the child is force-failed and the parent resumes
  through its `failed` handle.

## Key `FlowSession` fields

| Field | Type | Purpose |
| ----- | ---- | ------- |
| `flow_definition_id` | ULID | Pinned at start, **never changes** for the life of the session (snapshot) |
| `status` | enum | `pending` (unused), `active`, `waiting_input`, `paused`, `paused_subflow`, `completed`, `ended`, `failed`, `cancelled`, `expired`, `terminated_by_user` |
| `end_status` | string or null | `success`, `cancelled`, `failed`; set only when the session ended through an `end` node (or a forced subflow failure) |
| `current_node_id` | string or null | The node the engine runs next; null once the session is finished |
| `state` | JSONB | Session variables (namespaced: `system.*`, `flow.*`, `rag.*`, `call.*`) |
| `version` | int | Optimistic lock: `UPDATE ... WHERE version = N` guards concurrent writes |
| `parent_session_id` | ULID or null | Parent link for a subflow child; null for a top-level session |
| `parent_resume_node_id` | string or null | The parent's subflow node id, recorded on the child at creation. Informational: the resumer continues from the parent's own `current_node_id` |
| `expires_at` | timestamp or null | Subflow deadline; the starter extends the parent's expiry past the child's |

## Important invariants

- `flow_definition_id` is immutable after creation: a session always runs its own snapshot.
- Parent resume handle = the child's `end_status` (`success` / `cancelled` / `failed`).
- Subflow depth is at most 3, checked by `CallGraphValidator` at validation / publish time, not at runtime.
- Optimistic lock (`version`) plus the distributed Redis session lock are two independent layers
  against concurrent execution.

## Related

- [02-flow-engine-loop.md](02-flow-engine-loop.md)
- [06-subflow-lifecycle.md](06-subflow-lifecycle.md)
- `.ai/knowledge/domains/flow/OVERVIEW.md`
