# Node · `branch`

Evaluates a list of rules against a resolved operand and selects the output handle of the
first match, falling back to `default`.

**Type:** `branch`
**Version:** 1
**Category:** `Logic`
**Handler:** `App\Domains\Flow\Handlers\BranchNodeHandler`

## Config

```json
{
  "check": "flow.status",
  "rules": [
    {"handle": "approved", "operator": "eq", "value": "approved"},
    {"handle": "rejected", "operator": "eq", "value": "rejected"}
  ]
}
```

A rule may instead carry a structured operand under `left` (written by the builder's
operand picker), resolved by the shared `OperandResolver`:

```json
{
  "left": {"ref": "user_variable", "variable": {"name": "status", "storage": "session"}},
  "operator": "eq",
  "value": "approved",
  "handle": "approved"
}
```

```json
{
  "left": {"ref": "source", "source": "contact", "field": "form.input1"},
  "operator": "eq",
  "value": 1,
  "handle": "first"
}
```

**Fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `check` | string (state path) | no | Node-level default operand path, used by a rule that has no `left`. |
| `rules` | list of `{handle, operator, value, left?}` | yes (at least one expected) | Evaluated in order; the first rule whose operator matches its resolved operand against `value` wins. |

**Rule `left` shapes** (`OperandResolver::resolve()`):

- absent / `left` is a non-empty string → treated as a legacy bare path (same resolution as `check`).
- `{"ref": "user_variable", "variable": {...}}` (or flat `name`/`storage`/`group`) → resolved through `VariableResolverInterface`, honoring the session's `stateReader` when present.
- `{"ref": "source", "source": "<namespace>", "field"|"path": "<field>"}` → resolved as `"<namespace>.<field>"` through the legacy-path resolver.
- if `left` is absent/empty and the rule has no fallback, the node-level `check` (`$defaultPath`) is used; if that is also absent, `OperandResolver` throws `InvalidNodeConfigException`.

**Legacy path resolution** (`resolveLegacyPath()`) accepts `module.<prefix>.<key>` (routed through `DataAccessorRegistryInterface`) and any path starting with `flow.`, `system.`, `rag.`, `contact.` or `call.` (read via `data_get()` against state). A path ending in `.length` whose direct value is absent falls back to `count()` of its parent array (the "`.length` pseudo-accessor"). Any other namespace throws `InvalidNodeConfigException`.

## Operators (`BranchOperator`)

`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `contains` (`str_contains` on the string-cast value), `in` (`in_array` against an array `value`), `empty`, `not_empty` (PHP `empty()`/`!empty()`). All comparisons in `OperatorComparator::matches()`; an unknown/missing operator never matches.

## Output handles

- Each rule's own `handle` (defaults to `'default'` when a matched rule has no `handle` string).
- `'default'` when no rule matches.

There is no separate configured `default_handle` field — the handler always falls back to the literal string `'default'`.

## Behavior

1. `check` (if a non-empty string) becomes the node-level `$defaultPath`.
2. Rules are evaluated in array order (`evaluateRules()`): resolve the rule's operand via `OperandResolver::resolve($rule, $defaultPath, $state, $context)`; record `resolved[$path] = $value` when a path was produced; test `OperatorComparator::matches($value, $rule['operator'], $rule['value'])`.
3. The first matching rule returns its `handle` (or `'default'`); non-array rule entries are skipped.
4. No match → `'default'`. If `check` was configured and no `resolved` entries were collected (e.g. an empty `rules` list), `resolved[$defaultPath]` is additionally computed for log parity.
5. Result is always `NodeExecutionStatus::Executed` (branch never waits) with `logResolved` = every operand path/value touched during evaluation, and `metadata.expression = {operand, operator, expected}` describing the matched (or attempted) rule.

## State written

None — `branch` only reads state; it never returns `stateChanges`.

## Side effects

None beyond `module.*` operand reads through `DataAccessorRegistryInterface` (which may call out to an external data source registered for that module).

## Idempotency

Pure function of state — reading the same state always yields the same handle.

## Related

- [02-input.md](02-input.md) — often precedes `branch` to route on a captured value.
- [../../../../platform/runtime/flow/10-registered-nodes-catalog.md](../../../../platform/runtime/flow/10-registered-nodes-catalog.md) — full handler catalog.
