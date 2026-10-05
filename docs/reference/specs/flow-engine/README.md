# Flow Engine: design specs

Design specs for the flow engine runtime: state model, common concepts, call transport, validation and the
per-node contracts. The code is the authority; these documents explain how it behaves and why.

Plans and the V1.x backlog live in `.ai/specs/` (see `.ai/specs/flow-engine-v1x/`). Accepted architecture
decisions are in `.ai/knowledge/adr/`; the domain overview and rules are in `.ai/knowledge/domains/flow/`.

## Specs

| File | Contents |
|------|----------|
| [01-state-model.md](01-state-model.md) | Namespaces, contact addressing, reserved keys, `system.*` keys |
| [02-common-concepts.md](02-common-concepts.md) | Variable, expression, output handles, node JSON snapshot |
| [04-call-transport-layer.md](04-call-transport-layer.md) | `CallTransportInterface`, `ActionHandlerInterface`, fail-on-conflict registries |
| [06-validation.md](06-validation.md) | What validates a flow at save, validate and publish time |
| [node-usage-statistics.md](node-usage-statistics.md) | `flow:node-usage`: static and runtime usage of `type@version` |

The handler interface, execution loop and session lifecycle are documented elsewhere:

- the runtime guides in `docs/platform/runtime/flow/` (node development guide, cookbook, test matrix, config
  conventions, registered nodes catalog),
- the diagrams in [../../diagrams/](../../diagrams/README.md) (webhook pipeline, execution loop, tenant context,
  session state machine, subflow lifecycle),
- the published reference for extension authors in `docs/site/extending/flow-nodes/`.

## Nodes

All 15 handlers registered in `FlowServiceProvider::registerCoreNodeHandlers()`. The catalog is
[nodes/README.md](nodes/README.md).

## Legacy node types

Older definitions may still contain node types that are no longer registered (`condition`, `webhook`,
`set_attribute`). They are not executable; the engine fails with `HandlerNotFoundException` when it reaches one.
`ValidateFlowService` still accepts a legacy `condition` node in its branch-rule check. The `call` and `assign`
handlers keep a legacy config form (a node without `transport` / without `operations`) so old snapshots execute
identically.
