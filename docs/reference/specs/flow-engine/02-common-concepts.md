# 02. Common Concepts

## 2.1 Variable definition

Used by `input`, `assign`, `send_message` (`save_to_variable`) and `branch`. One format
(`App\Domains\Flow\State\Variables\Variable`).

```json
{
  "variable": {
    "name": "input1",
    "storage": "contact",
    "group": "form",
    "type": "text"
  }
}
```

Fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | yes | Variable name (identifier: letters, digits, underscore, not starting with a digit; not in `Variable::RESERVED_NAMES`) |
| `storage` | enum | yes | `contact` or `session` (`VariableStorage`) |
| `group` | string or null | no | Group name (identifier). `null` is the root. Contact storage only; `meta` is reserved |
| `type` | enum or null | no | `VariableType`: `text`, `number`, `boolean`, `phone`, `email`, `confirm`, `date`, `contact`, `file`, `photo`, `location`, `select`, `json`, `array`. Drives coercion when the variable is read; an unknown type string is ignored |
| `properties` | object | no | Extra schema metadata (for arrays: `item_type`, `max_size`) |

The identity of a variable is the triple `(storage, group, name)`. The path is resolved from
`storage + group + name` by `VariableResolver`; the `type` does not change the path, only how a read
value is coerced (the declared type, or the tenant variable schema when the variable has none).
How the data is captured is set separately, by `input.expected_type` (see [nodes/02-input.md](nodes/02-input.md)).
Typed declarations are collected at publish time into `tenant_variable_schema`, and a conflicting
type from another flow is rejected (see [06-validation.md](06-validation.md)).

**Where it is physically stored:**

| storage | group | Resolved path |
|---------|-------|---------------|
| `contact` | null | `contact.<name>` (in `attributes`) |
| `contact` | `form` | `contact.form.<name>` (nested in `attributes`) |
| `session` | null | `flow.<name>` (in `flow_sessions.state.flow`) |
| `session` | `params` | not allowed: session variables have no group |

## 2.2 Expression

Every value that may be a variable is an `Expression`. An expression is a **string with
placeholders**:

```
"Hello, {{contact.first_name}}"
"{{flow.code}}"
"{{contact.form.input1}} {{contact.form.input2}}"
```

A plain string without placeholders is a literal. A pure placeholder yields the value at that path.

Evaluation is a pluggable strategy behind `ExpressionEngineInterface`. The engine is chosen per flow
definition: the engine id is stored in `flow_definitions.expression_engine` (default `template`,
`TemplateEngine::ID`) and the runtime falls back to `template` when the stored id is empty or not
registered. There is no per-tenant setting for it. Only the built-in `template` engine (`{{path}}`
substitution) ships; more engines can be registered through `ExpressionEngineRegistry`.

**Namespaces readable in an expression:**

- `system.*`
- `flow.*`
- `rag.*`
- `call.*`
- `contact.*` (through the reader)
- `module.<name>.*` (through a `DataAccessorInterface`)

## 2.3 Output handles

Every node returns a `NodeExecutionResult` with a `sourceHandle`; the engine resolves the next node
by edge lookup (`from` = node, `handle` = `sourceHandle`). Handlers are graph-unaware. Standard
handles per node are described in `nodes/`.

## 2.4 Node JSON snapshot

The base structure is the same for every node:

```json
{
  "id": "01HQ...",
  "type": "<node_type>",
  "version": 1,
  "config": {
    /* node-specific config */
  }
}
```

`id` is a per-node id in the flow graph, `type` is the handler type in the registry, `version` is the
handler contract version (a missing value is read as `1`), `config` is node-specific. The entry node
is not stored: it is the single node with no incoming edge.

---

## Related

- [01-state-model.md](01-state-model.md)
- [06-validation.md](06-validation.md)
- `docs/site/extending/flow-nodes/` - the node handler contract for extension authors
