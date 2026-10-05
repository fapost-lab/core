---
id: convention-node-handler-boundary
type: convention
status: active
domains:
  - flow
paths:
  - "app/Domains/Flow/Handlers/**"
summary: "What a node handler may and must never do: persistence, transactions, system.*, own state"
reviewed_at: 2026-10-05
---
# Node handler boundary

`Fapost\Foundation\Contracts\NodeHandlerInterface`, implemented in Core through
`AbstractVersionedHandler` and by extensions.

## Practice

```
MAY:      read the state it is given; return stateChanges for its own namespaces
MUST:     return a sourceHandle, never the id of the next node
MUST NOT: persist FlowSession; commit a transaction; resolve another handler;
          write system.* unless its type is whitelisted in SystemStateNamespacePolicy;
          keep state in its own properties between executions
```

## Example

`SetTagNodeHandler::execute()` changes tags through a service injected in its constructor,
returns its idempotency marker `system.set_tag.<node>` as a `stateChanges` entry, and returns
`NodeExecutionResult::executed(...)` on the default handle. It saves no session, opens no
transaction and never looks at the graph; on a replay it sees the marker and does nothing.

## Rationale

The engine owns persistence, the transaction and graph navigation, so a whole step can be
retried or rolled back as one unit. A handler is built per resolve in the current scope
(ADR-0001); property state would survive only by accident and would leak between jobs in a
long-lived worker. The `system.*` whitelist keeps runtime bookkeeping out of reach of
arbitrary nodes.
