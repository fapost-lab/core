# Flow Engine: per-node specs

The 15 Core handlers registered in `FlowServiceProvider::registerCoreNodeHandlers()`. The same list, with
categories and classes, is `docs/platform/runtime/flow/10-registered-nodes-catalog.md`.

| File | Node type | Handler |
|------|-----------|---------|
| [01-send-message](01-send-message.md) | `send_message` | `SendMessageNodeHandler` |
| [02-input](02-input.md) | `input` | `InputNodeHandler` |
| [03-branch](03-branch.md) | `branch` | `BranchNodeHandler` |
| [04-delay](04-delay.md) | `delay` | `DelayNodeHandler` |
| [05-assign](05-assign.md) | `assign` | `AssignNodeHandler` |
| [06-call](06-call.md) | `call` | `CallNodeHandler` |
| [07-emit-event](07-emit-event.md) | `emit_event` | `EmitEventNodeHandler` |
| [08-subflow](08-subflow.md) | `subflow` | `SubflowNodeHandler` |
| [10-end](10-end.md) | `end` | `EndNodeHandler` |
| [11-loop](11-loop.md) | `loop` and `loop_end` | `LoopNodeHandler`, `LoopEndNodeHandler` |
| [set-tag](set-tag.md) | `set_tag` | `SetTagNodeHandler` |
| [notify](notify.md) | `notify` | `NotifyNodeHandler` |
| [auth-request](auth-request.md) | `auth_request` | `AuthRequestNodeHandler` |

`rag_query` (`RagQueryNodeHandler`) is registered too and has no standalone node spec here; see
`.ai/specs/rag-knowledge-bases/`.

`condition`, `webhook` and `set_attribute` are legacy types and are not registered.

---

## Related

- [../README.md](../README.md): the flow engine spec index
- `docs/platform/runtime/flow/06-node-development-guide.md`: how to add a node
