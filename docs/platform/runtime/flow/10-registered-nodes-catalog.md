# 10. Registered Nodes Catalog

Reflects the handlers actually registered in `FlowServiceProvider::registerCoreNodeHandlers()`
(called from the `NodeHandlerRegistry` singleton factory in `register()`). All 15 are Core
handlers at `v1`.

| Type | Version | Category | Class | Purpose | Spec |
|---|---:|---|---|---|---|
| `send_message` | 1 | `Core` | `SendMessageNodeHandler` | Sends text/media content with an optional inline or reply keyboard; can wait for a button press or a configured timeout. | [01-send-message.md](../../../reference/specs/flow-engine/nodes/01-send-message.md) |
| `input` | 1 | `Core` | `InputNodeHandler` | Prompts the contact, parks until a matching reply arrives, validates it and stores it — or ingests media attachments. | [02-input.md](../../../reference/specs/flow-engine/nodes/02-input.md) |
| `branch` | 1 | `Logic` | `BranchNodeHandler` | Evaluates ordered rules against a resolved operand and routes through the first match's handle, else `default`. | [03-branch.md](../../../reference/specs/flow-engine/nodes/03-branch.md) |
| `delay` | 1 | `Logic` | `DelayNodeHandler` | Pauses the session for a configured number of seconds via a scheduled resume job. | [04-delay.md](../../../reference/specs/flow-engine/nodes/04-delay.md) |
| `assign` | 1 | `Data` | `AssignNodeHandler` | Writes one or more computed values into flow/contact state. | [05-assign.md](../../../reference/specs/flow-engine/nodes/05-assign.md) |
| `call` | 1 | `Integration` | `CallNodeHandler` | Invokes an external HTTP endpoint or an in-process action handler through a pluggable transport; routes on `success`/`error`. | [06-call.md](../../../reference/specs/flow-engine/nodes/06-call.md) |
| `emit_event` | 1 | `Logic` | `EmitEventNodeHandler` | Publishes a tenant-scoped event, fire-and-forget, so subscribed event-triggers can start other flows. | [07-emit-event.md](../../../reference/specs/flow-engine/nodes/07-emit-event.md) |
| `subflow` | 1 | `Logic` | `SubflowNodeHandler` | Pauses the parent session and starts a child flow session, resuming the parent when the child ends. | [08-subflow.md](../../../reference/specs/flow-engine/nodes/08-subflow.md) |
| `rag_query` | 1 | `AI` | `RagQueryNodeHandler` | Queries a RAG provider via `RagAdapterRegistry` and writes the structured result into `state.rag.*`. | No standalone node spec; see `.ai/specs/rag-knowledge-bases/` |
| `end` | 1 | `Logic` | `EndNodeHandler` | Terminal node — marks the session ended with a discriminated status (`success`/`cancelled`/`failed`). | [10-end.md](../../../reference/specs/flow-engine/nodes/10-end.md) |
| `loop` | 1 | `Logic` | `LoopNodeHandler` | Evaluates a loop continuation condition (counted or while) and routes into the loop body (`loop`) or past it (`default`). | [11-loop.md](../../../reference/specs/flow-engine/nodes/11-loop.md) |
| `loop_end` | 1 | `Logic` | `LoopEndNodeHandler` | Increments the loop iterator and signals the engine to navigate back to the parent `loop` node (engine special-case, no edge). | [11-loop.md](../../../reference/specs/flow-engine/nodes/11-loop.md) |
| `set_tag` | 1 | `Data` | `SetTagNodeHandler` | Adds, removes or toggles a tag on the current contact. | [set-tag.md](../../../reference/specs/flow-engine/nodes/set-tag.md) |
| `notify` | 1 | `Contact` | `NotifyNodeHandler` | Sends a queued notification, either to staff (admin-panel escalation) or to contacts (broadcast via an assistant). | [notify.md](../../../reference/specs/flow-engine/nodes/notify.md) |
| `auth_request` | 1 | `Contact` | `AuthRequestNodeHandler` | Evaluates a challenge (currently `basic`: operator comparison) and, on a pass, raises the `contact.is_authenticated` flag. Always continues through `default`. | [auth-request.md](../../../reference/specs/flow-engine/nodes/auth-request.md) |

`category()` values above come from each handler's own override; `SendMessageNodeHandler` and
`InputNodeHandler` don't override it and fall back to `AbstractVersionedHandler::category()`,
which returns `'Core'`.

## How registration works

`FlowServiceProvider::register()` binds `NodeHandlerRegistry` as a singleton whose factory calls
`registerCoreNodeHandlers()`, which lists the 15 handler classes above and calls
`$registry->register($handlerClass)` for each. Per
[ADR-0001](../../../../.ai/knowledge/adr/0001-node-handlers-built-per-scope.md), the registry
keeps only the handler **class** (a throwaway instance is built once at registration to read and
validate `type()`/`version()`); `resolve()` and `all()` then build a fresh instance on every call
through `NodeHandlerFactoryInterface` (`ContainerNodeHandlerFactory`), which always resolves
through the container's current scope. This is what lets handler constructors take ordinary
scoped dependencies (tenant context, translators, senders) without leaking them across Horizon
jobs for different tenants. `FlowServiceProvider::boot()` freezes the registry outside the
`testing` environment so Solutions/Plugins can no longer add handlers after boot.

## Keeping this catalog current

If you add a new node:

1. Register its handler class in `FlowServiceProvider::registerCoreNodeHandlers()`.
2. Add a row here and a spec file under `docs/reference/specs/flow-engine/nodes/`.
