# Node: `assign`

Writes values into session or contact state.

**Type:** `assign` · **Version:** 1 · **Category:** `Data`
**Class:** `app/Domains/Flow/Handlers/AssignNodeHandler.php`

## Config

```json
{
  "operations": [
    {
      "variable": {"name": "full_name", "storage": "contact"},
      "value": "{{contact.first_name}} {{contact.last_name}}"
    },
    {
      "variable": {"name": "reason", "storage": "session"},
      "value": "vip"
    },
    {
      "variable": {"name": "completed_at", "storage": "contact", "group": "form"},
      "value": "{{flow.finished_at}}"
    }
  ]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `operations` | list | yes (new form) | Operations, run in order |

**Operation:**

| Field | Type | Description |
|-------|------|-------------|
| `variable` | Variable | The target: `{name, storage, group?, type?, properties?}` (see [../02-common-concepts.md](../02-common-concepts.md)). A static structure, never a template, so the target is known statically |
| `value` | template | Rendered by `TemplateRenderer` against the live state. This is placeholder substitution; there is no arithmetic (`{{x}} + 1` is not evaluated) |

The path is resolved by `VariableResolver::resolveTargetPath()`: `contact` storage gives `contact.<name>` or
`contact.<group>.<name>`; `session` storage gives `flow.<name>`.

### Legacy form

A node without `operations` is read as the legacy single write `{target: "flow" | "contact", key, value}` (and
`configSchema()` still declares only this form). `target` is an `AssignTarget`. For `contact`, the key
`language` (or `contact.language`) aliases the canonical language column; any other key is a contact attribute.
A missing target or key throws `InvalidNodeConfigException`. Legacy snapshots keep executing identically.

## Output handles

- `default`: always. There is no `success` or `error` handle; every failure throws and fails the session.

## Behavior

For each operation, in order:

1. The operation must be an object with a complete `variable` (name and storage); otherwise
   `InvalidNodeConfigException` is thrown.
2. Render `value`.
3. Resolve the target path and write by storage:
   - **contact:** `ContactWriterInterface::write($path, $value)`, an immediate write that commits in its own
     transaction. The writer enforces the contact rules (reserved keys, depth, leaf-vs-group conflict; see
     [../01-state-model.md](../01-state-model.md), 1.2 and 1.4). A violation throws, which fails the session. If no
     writer is available, `InvalidNodeConfigException`.
   - **session, type `array`:** append the value to the current list (the new list goes into `stateChanges`).
   - **session, otherwise:** `stateChanges[$path] = $value`.
4. Return `Executed` with `default`, the collected `stateChanges` and metadata `paths` (the applied paths).

Session writes land in `FlowSession.state` through `FlowSessionPersister`, which also bumps the version under the
optimistic lock. A re-run writes the same value, so the node is idempotent, except for an array target, which
appends again.

There is no read-after-write inside one node: `value` is rendered against the state as it was when the node
started, so two operations on the same variable do not see each other's session writes.

## Validation

`ValidateFlowService` has no assign-specific rule. Contact path rules are runtime guards in `ContactWriter`.
`FlowDefinitionValidator` (not wired into the save / publish pipeline) additionally checks that the new and legacy
shapes do not coexist and that `operations[]` address unique `(storage, group, name)` triples. Publish collects
the declared variables into the tenant variable schema and rejects cross-flow type conflicts (see
[../06-validation.md](../06-validation.md)).

---

## Related

- [../01-state-model.md](../01-state-model.md), [../02-common-concepts.md](../02-common-concepts.md)
- [11-loop.md](11-loop.md) - appending to array variables
