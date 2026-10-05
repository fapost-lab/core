# Node: `loop` / `loop_end`

**Document:** Loop node specification for the Flow Engine (addendum), aligned with the code (verified
against `LoopNodeHandler`, `LoopEndNodeHandler`, `ValidateFlowService`, `PublishFlowService`,
`ContactWriter`).
**Related documents:** [../README.md](../README.md), [../01-state-model.md](../01-state-model.md),
[../06-validation.md](../06-validation.md), [../node-usage-statistics.md](../node-usage-statistics.md)

## Implementation status

**Built (checked against the code):**

- **Engine and handlers:** `LoopNodeHandler` (counted and while modes, structured-left `count_source` /
  `condition`, literal form `{type: 'literal', value: N}`, iterator init and cleanup) and
  `LoopEndNodeHandler` (iterator increment). The engine navigates `loop_end -> config.loop_node_id` as a
  special case in `FlowEngine::executeLoop()`. Both are registered in `FlowServiceProvider::registerCoreNodeHandlers()`.
- **Per-loop total.** The counted total is `flow.{iterator_name}_total` (`LoopNodeHandler::TOTAL_SUFFIX`),
  not a global `flow.iteration_total`, so sibling or sequential loops never collide.
- **`iterator_name` is denormalised** into the `loop_end` config at publish
  (`PublishFlowService::denormalizeLoopEndIteratorNames`).
- **Array type:** `VariableType::Array`, pass-through in `VariableCoercer`; `ContactWriter` appends with a
  circular buffer (`max_size` through a lazy schema-registry resolver); session variables append in
  `AssignNodeHandler` and `InputNodeHandler`; media input into an array variable appends each descriptor as its own
  element.
- **Registry delta:** `tenant_variable_schema.properties` (jsonb, default `{}`), `getProperties()` on
  `VariableSchemaRegistryInterface` / `CacheBackedVariableSchemaRegistry`; properties are collected by
  `VariableSchemaCollector` and upserted at publish.
- **Validation** (all are errors in `ValidateFlowService`, returned by validate and enforced at publish):
  `loop_end_missing_loop_node_id` / `loop_end_invalid_loop_node_id`, `loop_invalid_iterator_name`,
  `loop_reserved_iterator_name`, `loop_nested_not_allowed`, `loop_missing_loop_end` (reachability BFS from the
  `loop` handle), `loop_count_exceeds_budget` (literal count above 100).
- **Registry conflicts:** an array variable whose `item_type` differs between flows is rejected at publish
  (`variable_properties_conflict`, `PublishFlowService::assertNoVariableTypeConflicts`); `max_size` is not
  compared (it is registry-only, see 7.3).
- **Builder:** the `loop_end` type is excluded from the palette (`NodeTypesController::AUTO_MANAGED_TYPES`),
  created automatically with a loop, self-healed on draft load (`builderStore.healLoopConstructs`) and removed only with its
  loop. `FlowLoopCard` has a "Loop body" entry for the `loop` branch while the `default` chain continues
  linearly; `LoopConfig.vue` is a bespoke config (modes, fixed or variable count, while-condition through
  `ConditionOperandPicker`, advanced `iterator_name`). Arrays are produced by the **"Store as list"**
  toggle on a variable (`VariableStorageEditor`), which compiles to `type: 'array'` plus
  `properties.item_type`; the element type comes from the input's `expected_type`.
- **Condition `.length`:** `OperandResolver::resolveWithLength` makes `contact.photos.length` yield the element
  count in conditions; a real `length` key wins over the pseudo-accessor.
- **Tests:** `tests/Feature/Domains/Flow/LoopEngineTest.php`, `LoopNodeHandlerTest.php`, and the loop cases in
  `tests/Unit/Domains/Flow/ValidateFlowServiceTest.php`.

**Not built:**

- Rejecting session variables named like an active `iterator_name` or `{iterator_name}_total` (8.3, second part).
- A warning when a while condition is not changed in the body (8.5).
- A static type check of a numeric `count_source` (8.7); a non-numeric runtime value fails the session in the handler.
- A decision on the iteration budget (14.1): the general `flow.execution.max_iterations` (default 100) applies, plus the
  literal-count rule 8.8. Batch loops with no wait node hit the limit at roughly 30 iterations.
- `{{....length}}` in `TemplateEngine` message templates (14.3); only conditions support it.
- A UI to edit `max_size` (the default 100 comes from the registry), see 7.3.
- Validation severities "warning vs error": `ValidateFlowService` has no warning level; every rule
  above is an error.

---

## 1. Overview

The loop node adds repetition to the Flow Engine. Use cases:

- a series of inputs that accumulate ("upload 5 photos"),
- repeat until a condition ("ask until we get a valid answer"),
- batch operations with a known count.

Two modes: **counted** (N iterations) and **while** (while a condition is true).

The document covers the two nodes `loop` and `loop_end`, the `array` variable type, the delta to the
variable schema registry (`properties`), the validation rules and the engine changes that were needed.

---

## 2. Code changes (summary)

| Area | Change |
|------|--------|
| `FlowEngine::executeLoop()` | Special-case navigation `loop_end -> config.loop_node_id` (precedent: `end`, `subflow`) |
| Handlers | `LoopNodeHandler`, `LoopEndNodeHandler` (Core, graph-unaware) |
| `VariableType` | `Array = 'array'` |
| `VariableCoercer` | array pass-through |
| `ContactWriter` | append + circular buffer for array variables; `max_size` through a lazy registry resolver |
| `tenant_variable_schema` | column `properties jsonb NOT NULL DEFAULT '{}'` |
| `VariableSchemaCollector` / `PublishFlowService` | collect and compare `item_type` in conflict detection |
| `ValidateFlowService` | the loop rules in section 8 |
| `InputNodeHandler`, `AssignNodeHandler` | append when writing to an array variable |
| `SystemStateNamespacePolicy` | no change: `flow.*` is open to all handlers |
| Builder (Vue) | "Store as list" in `VariableStorageEditor`; loop / loop_end config panels |

---

## 3. The loop node

### 3.1 Type and version

- **Type:** `loop`
- **Version:** 1
- **Idempotent:** yes. The node only reads the condition and writes state through `stateChanges`; the batch is
  persisted with the transition under the optimistic lock.

### 3.2 Config

Operands (`count_source`, `condition.left`) use the existing structured-`left` format (`OperandResolver`:
`ref: user_variable | source`); operators are `BranchOperator`. The UI is `ConditionOperandPicker.vue`.

#### Counted mode

```json
{
  "id": "01HQ_loop_node",
  "type": "loop",
  "version": 1,
  "config": {
    "mode": "counted",
    "count_source": {"ref": "source", "source": "contact", "path": "photo_count"},
    "iterator_name": "iterator"
  }
}
```

A literal count is `"count_source": {"type": "literal", "value": 5}`.

#### While mode

```json
{
  "id": "01HQ_loop_node",
  "type": "loop",
  "version": 1,
  "config": {
    "mode": "while",
    "condition": {
      "left": {"ref": "user_variable", "variable": {"name": "user_done", "storage": "session"}},
      "operator": "eq",
      "value": false
    },
    "iterator_name": "iterator"
  }
}
```

### 3.3 Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `mode` | enum | no | `counted` (default) or `while` |
| `count_source` | structured left or literal | counted mode | Source of the number of iterations |
| `condition` | structured rule (`left`, `operator`, `value`) | while mode | Loop continuation condition |
| `iterator_name` | string | no | Iterator name in session state. Default `iterator`. Identifier characters only; the names `id`, `language`, `meta`, `tenant_id`, `external_id` are reserved |

### 3.4 Output handles

| Handle | Taken when |
|--------|-----------|
| `loop` | the iteration condition holds: run the loop body |
| `default` | the condition does not hold: skip the loop and continue |

Both are ordinary edges in `flow_definitions.edges` (`{from, to, handle}`).

### 3.5 Iterator semantics

**1-based.** `flow.{iterator_name}` is **1** on the first iteration and is incremented by `loop_end` on each return.

**Counted:**

- first arrival: `flow.{iterator_name} = 1`; every pass writes `flow.{iterator_name}_total` = the resolved
  `count_source`
- continue while `flow.{iterator_name} <= flow.{iterator_name}_total`
- true -> `loop` handle, false -> `default` handle

**While:**

- first arrival: `flow.{iterator_name} = 1`; no total is set
- continue while the `condition` holds
- true -> `loop`, false -> `default`

**Access from expressions** (`TemplateEngine`):

```
"Take photo #{{flow.iterator}} of {{flow.iterator_total}}"   <- counted, iterator_name = iterator
"Question #{{flow.iterator}}"                                  <- any mode
```

### 3.6 Behavior

1. If `flow.{iterator_name}` is `null` (not initialised), `stateChanges` sets it to 1. The check is strictly
   `null === data_get(...)`: the persister never deletes keys, cleanup is a written `null`.
2. Evaluate the continuation condition:
   - **counted:** evaluate `count_source`, write the total to `flow.{iterator_name}_total`, test
     `iterator <= total`. A non-numeric value throws (`RuntimeException`), failing the session.
   - **while:** evaluate `condition` with `OperandResolver` and `OperatorComparator`.
3. True -> `sourceHandle: "loop"`.
4. False -> cleanup (write `null` into `flow.{iterator_name}`, and for counted also into
   `flow.{iterator_name}_total`, through `stateChanges`) -> `sourceHandle: "default"`.

A missing `count_source` (counted) or `condition` (while) throws `InvalidNodeConfigException`.

**Cleanup on exit** lets sequential loops reuse an `iterator_name` and keeps dead values out of state.

---

## 4. The loop_end node

### 4.1 Type and version

- **Type:** `loop_end`
- **Version:** 1
- **Category:** `Logic`; auto-managed (hidden from the palette), empty config schema
- **Idempotent:** yes. The increment is persisted with the navigation in one transaction under the optimistic
  lock, so a retry does not double-increment.

### 4.2 Config

```json
{
  "id": "01HQ_loop_end_node",
  "type": "loop_end",
  "version": 1,
  "config": {
    "loop_node_id": "01HQ_loop_node",
    "iterator_name": "iterator"
  }
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `loop_node_id` | string | yes | Id of the parent loop node to return to |
| `iterator_name` | string | no | Copied from the parent loop at publish; the handler defaults to `iterator` |

### 4.3 Return navigation is engine-level

There are no output handles and no edges. The "handlers are graph-unaware" rule holds like this:

- the **handler** (`LoopEndNodeHandler`) reads `iterator_name` from its own config (denormalised by
  `PublishFlowService` at publish) and returns `Executed` with a `null` source handle and a `stateChanges`
  incrementing the iterator;
- the **engine**, in `executeLoop()`, special-cases `LoopEndNodeHandler::TYPE` (like `EndNodeHandler::TYPE` and
  `SubflowNodeHandler::TYPE`) and sets `nextNodeId = config.loop_node_id` instead of an edge lookup.

The builder may draw a dashed return arrow as a visual cue; it is not an edge in the data.

### 4.4 Behavior

1. Increment `flow.{iterator_name}` by 1 (through `stateChanges`).
2. The engine moves execution to `loop_node_id` (the condition is re-evaluated).

**A failed iteration fails the session.** If a node in the loop body fails, the whole session fails (a
handler exception goes through `FlowEngine::markSessionFailed()`); the loop's exit semantics do not apply. An iteration counts
as done only when `loop_end` is reached.

---

## 5. The array type

### 5.1 Context

Contact attributes hold **leaf** (scalar) and **group** (nested object) values. The loop adds a third kind,
**array**:

| Kind | JSON type | Example |
|------|-----------|---------|
| leaf | string, number, boolean, null | `"Ivan"`, `42` |
| group | object | `{"city": "Kyiv"}` |
| array | array | `["url1", "url2"]` |

In code this is `VariableType::Array`; the existing `Json` type stays for a whole structured payload (for
example a `call` response).

### 5.2 Append semantics

Writing to an array variable **appends**, it does not replace (`ContactWriter`):

```
Initial:           contact.photos = []
After 1st input:   contact.photos = ["url1"]
After 2nd input:   contact.photos = ["url1", "url2"]
```

This applies always to an array variable, not only inside a loop. `ContactWriter` treats a path as an array
variable when the tenant variable schema registry declares it with type `array`; before the first publish of a
flow that declares it, the registry has no entry and a write replaces.

### 5.3 Circular buffer at the limit

An array variable has a `max_size` (default 100, from the registry `properties`). Appending to a full array
shifts left by one: the oldest element is dropped. This bounds memory and protects against runaway loops.

### 5.4 Input into an array

The author turns on **"Store as list"** on the variable of an `input` node. The variable compiles to a
`variable` block with `type: "array"` and the element type in `properties.item_type`; the element type is derived
from the input's `expected_type`:

```json
{
  "type": "input",
  "version": 1,
  "config": {
    "expected_type": "photo",
    "variable": {
      "name": "photos",
      "storage": "contact",
      "group": null,
      "type": "array",
      "properties": {"item_type": "photo"}
    }
  }
}
```

Each reply from the user appends one element to `contact.photos`. A media input appends every ingested descriptor
as its own element.

### 5.5 Assign into an array

An `assign` operation whose target is an array variable also appends (the operations format is the actual
`AssignNodeHandler` contract):

```json
{
  "type": "assign",
  "config": {
    "operations": [
      {
        "variable": {"name": "photos", "storage": "contact", "group": null, "type": "array"},
        "value": "{{flow.last_photo_url}}"
      }
    ]
  }
}
```

Replacing a whole array is a separate operation that does not exist yet.

### 5.6 Reading an array

`TemplateEngine` resolves paths with `data_get`, so an index is written with dot syntax:

```
{{contact.photos}}     -> the whole array (JSON-encoded in text; rarely wanted)
{{contact.photos.0}}   -> the first element
{{contact.photos.2}}   -> the third element
```

The size (`{{contact.photos.length}}`) is **not** supported in templates. In branch and loop **conditions**
`OperandResolver::resolveWithLength` supports `.length`. Negative indices and slicing are not built.

### 5.7 In the builder

`VariableStorageEditor.vue` shows a "Store as list (append each value)" toggle; the card shows a read-only Type
label and a `[]` suffix (for example `photos[]`), and new inputs inside a loop body get the list toggle turned on.

---

## 6. The loop in the graph

### 6.1 Structure

```
                      +--------------+
                      |  Loop        |
                      |  counted: 5  |
                      +-+---------+--+
              loop ----+         +---- default
                |                       |
                v                       v
          [body node 1]            [next node]
                |
                v
          [LoopEnd]
                |
                +--- engine: nextNodeId = loop_node_id --> Loop (re-evaluate)
```

The `loop` / `default` edges are ordinary `flow_definitions.edges` rows; the return `loop_end -> loop` is
engine navigation with no edge (4.3).

### 6.2 An `end` inside the loop body

An `end` node is allowed in a loop body and ends the **whole flow**, not the iteration. This is the workaround
for `break`:

```
Loop:
  +- Input photo
  +- Branch: photo invalid?
  |     +- true  -> End (status=failed)   <- ends the whole flow
  |     +- false -> continue
  +- LoopEnd
```

A real `break` (leave only the loop and continue the flow) is not supported.

### 6.3 LoopEnd is mandatory

Every `loop` branch must lead to a reachable `loop_end` (rule 8.1).

---

## 7. Variable schema registry: the delta

The registry already exists: the `tenant_variable_schema` table (key `(storage, group, name)`),
`CacheBackedVariableSchemaRegistry` (per-tenant cache, `invalidate()` after publish), `VariableSchemaCollector`,
upsert and cross-flow conflict detection in `PublishFlowService` (`VariableTypeConflictException`).
`declared_in_flow_id` is a nullable FK with SET NULL, so records survive deleting a flow.

The loop needed only this delta:

### 7.1 The `properties` column

```sql
ALTER TABLE tenant_variable_schema
    ADD COLUMN properties jsonb NOT NULL DEFAULT '{}';
```

For an array: `{ "max_size": 100, "item_type": "photo" }`.

### 7.2 Properties in collect and conflict

`VariableSchemaCollector` collects the `properties` from the node config. For two array declarations of the
same variable, the conflict check in `PublishFlowService` compares the element type (`item_type`) and rejects
a mismatch with `variable_properties_conflict`:

```
Variable "contact.photos" is already registered as an array of "photo" elements; flow "Survey Photos" tries to
use it as an array of "file". Match the existing element type or use a different variable name.
```

`max_size` is intentionally not compared.

### 7.3 `max_size` source of truth

The registry, not the node config. A node snapshot does not store `max_size`; the writer looks it up at runtime
through the lazy resolver and falls back to 100 when the property is absent.

There is no UI to edit registry properties (changing them is a data migration, or drop and recreate the
variable while no data exists). A "Variables" section in the admin panel is future work.

---

## 8. Validation rules

Additions to [../06-validation.md](../06-validation.md). Save draft runs **no** validation; the explicit validate
call returns the errors, and publish enforces them atomically. There is no warning level: every rule below is an
error unless it is listed under "Not built".

### 8.1 LoopEnd is required (`loop_missing_loop_end`)

For every loop node, a BFS from the targets of its `loop` handle must reach a `loop_end` whose `loop_node_id`
points at this loop. A `loop_end` of another loop is a boundary and is not traversed past.

### 8.2 LoopEnd references an existing loop (`loop_end_missing_loop_node_id`, `loop_end_invalid_loop_node_id`)

`loop_node_id` must name an existing `loop` node in the same definition.

### 8.3 `iterator_name` (`loop_invalid_iterator_name`, `loop_reserved_iterator_name`)

Must match `^[A-Za-z_][A-Za-z0-9_]*$` and not be one of the reserved names `id`, `language`, `meta`, `tenant_id`,
`external_id`.

Not built: rejecting session variables that have the name of an active `iterator_name` or `{iterator_name}_total`.

### 8.4 No nested loops (`loop_nested_not_allowed`)

A BFS from the `loop` handle; another `loop` node in the reachable set means a nested loop.

### 8.5 A while condition must change (not built)

Intended: extract the paths from `condition.left` and check that at least one body node writes to them, else warn
about a possible infinite loop. It would be a warning only, since the condition may change from outside (for
example an event trigger of another flow writing to the contact).

### 8.6 Variable registry conflicts

Publish compares every variable of the flow with the registry; conflicts are refused (7.2).

### 8.7 Counted source must be a number (not built)

A best-effort static check was planned (literal type, a known registry type for `contact.*`, skip `flow.*`). A
non-numeric runtime value fails the session in the handler.

### 8.8 Literal count budget (`loop_count_exceeds_budget`)

A counted loop with a literal `count_source` above 100 is rejected. This is related to the engine limit
`flow.execution.max_iterations = 100` (`config/flow.php`): each loop iteration costs `body + 2` engine iterations
inside one `executeLoop()` pass. Loops with a wait node (`input`) are safe because each iteration runs in a
separate resume. **Batch loops without a wait node** hit the limit at roughly 30 iterations; there is no
validation for a runtime-valued count.

---

## 9. Iterator cleanup and sequential loops

### 9.1 Cleanup on exit

Leaving through the `default` handle nulls the state through `stateChanges` (the persister's null convention,
precedent: the retry counter in `InputNodeHandler`):

```php
$stateChanges['flow.' . $iteratorName]                      = null;
$stateChanges['flow.' . $iteratorName . '_total']           = null;   // counted mode
```

### 9.2 Sequential loops with the same `iterator_name`

```
Loop A (iterator_name=iterator) -> body A -> LoopEnd -> Loop A
Loop A exits -> flow.iterator = null
Loop B (iterator_name=iterator) -> body B -> LoopEnd -> Loop B
```

Correct: every loop starts at 1 (the initialisation check is `null ===`).

### 9.3 Parallel loops in different branches

Two branches, each with its own loop and the same `iterator_name`, are fine: only one branch runs.

---

## 10. Failure modes

| # | Scenario | Behaviour |
|---|----------|-----------|
| 1 | `count_source` is 0 or negative | `1 <= 0` is false -> straight to `default`, the body does not run. Not an error |
| 2 | `count_source` resolves to a non-number at runtime | handler exception -> session failed (`FlowEngine::markSessionFailed()`: failed status, flow log, FlowFailed analytics) |
| 3 | The while condition throws (for example a DataAccessor module failure) | session failed, as in 2 |
| 4 | `loop_node_id` points at a missing loop at runtime (manual DB intervention; publish validation excludes it) | session failed |
| 5 | A huge array fills up | the array stays at `max_size`, old elements drop out. Acceptable by design ("keep the last N items") |
| 6 | A batch loop exceeds the iteration budget | `FlowExecutionLimitExceededException`, session failed. See 8.8 / 14.1 |

---

## 11. Integration with the platform

- **State writes:** the loop relies on the atomic `stateChanges` batch plus navigation in one transaction under the
  optimistic lock (a retry-safe increment). History logging respects `logging_enabled`.
- **Concurrency:** long loops are covered by the lock heartbeat (`LockHeartbeat`, refreshed before each node); an
  incoming message during an input inside a loop goes through standard routing.
- **Expressions:** `count_source` and `condition` are structured forms, not expressions; `flow.iterator` and
  `flow.{iterator_name}_total` are reachable through `TemplateEngine`. The `.length` resolver exists for conditions
  only (14.3).
- **Subflow:** a subflow in a loop body creates a new child session per iteration (wait mode; `resumeAfterSubflow`
  returns to the post-subflow position inside the body). 100 iterations x a subflow is 100 child sessions:
  acceptable but heavy, use with care.

---

## 12. V1 scope

### In

- `loop` node type (counted and while)
- `loop_end` node type (engine-level return navigation)
- array variables through "Store as list" with `item_type`
- array storage in contact attributes JSONB; append plus circular buffer at `max_size`
- `tenant_variable_schema.properties` and `item_type` in conflict detection
- configurable `iterator_name` (default `iterator`), `flow.{iterator_name}_total` for counted, 1-based
- validation rules 8.1-8.4, 8.6, 8.8
- `end` inside a loop body ends the whole flow (the `break` workaround)
- iterator cleanup on exit (null assignment)

### Not in

- Break / Continue
- nested loops
- ForEach mode (iterate an existing collection with an `iteration_item`)
- a variables registry UI in the admin panel
- array operations beyond append: replace, remove, sort, filter
- negative indices and slicing in expressions
- per-flow override of variable properties (the registry is the single source of truth)
- strict while-condition validation (8.5)
- cleanup of orphan registry records
- node usage statistics: see [../node-usage-statistics.md](../node-usage-statistics.md)

---

## 13. Workflow examples

### 13.1 Counted loop with photos

```
1. Ask the user "How many photos will you send?"  -> number -> contact.photo_count
2. Loop (counted, count_source = contact.photo_count, iterator_name = iterator)
3. [loop]
   3a. Ask "Upload photo #{{flow.iterator}} of {{flow.iterator_total}}"
       -> list (item: photo) -> contact.photos
   3b. LoopEnd (loop_node_id -> step 2)
4. [default]
   Send "Thanks, photos received"
5. End (success)
```

Result: `contact.photo_count = 5`, `contact.photos = [url1..url5]`.

### 13.2 While loop with a confirmation

```
1. Assign: flow.user_done = false
2. Loop (while, condition: flow.user_done == false, iterator_name = iterator)
3. [loop]
   3a. Ask "Enter answer #{{flow.iterator}} (or /done)"  -> text -> contact.responses (list)
   3b. Branch: flow.last_response == "/done"
       - true  -> Assign flow.user_done = true
       - false -> continue
   3c. LoopEnd
4. [default]
   Send "Answers received"
5. End
```

### 13.3 Sequential loops

```
1. Ask "How many photos?" -> contact.photo_count
2. Loop A (counted, iterator_name = iterator) - body: ask photo -> contact.photos - LoopEnd
3. Loop A exits -> flow.iterator = null
4. Ask "How many questions?" -> contact.question_count
5. Loop B (counted, iterator_name = iterator) - body: ask text -> contact.questions - LoopEnd
6. End
```

Reusing `flow.iterator` is safe because of the cleanup on exit.

---

## 14. Open questions

1. **Iteration budget (8.8).** Option (a): do not count `loop` / `loop_end` against `max_iterations` and add a
   separate, larger loop-iteration limit, which makes counted loops with runtime values workable. Option (b): keep
   the shared budget plus rule 8.8. Option (a) is preferred. **Undecided.**
2. **Iterator name on `loop_end`.** Settled: denormalised by `PublishFlowService` at publish (4.3).
3. **`{{....length}}` in templates.** Conditions support it; message templates do not yet.
4. **ForEach mode:** iterate over an existing collection. On request.
5. **Break / Continue:** a formal mechanism. On request.
6. **Bulk array operations:** replace, remove, sort, filter. As needed.
7. **Iterator naming conventions:** recommend `i`, `idx`, `iter` in the docs?

---

## 15. Test strategy and acceptance

Tests that exist: `LoopNodeHandlerTest` (counted and while evaluation, cleanup, config errors),
`LoopEngineTest` (the engine returning to the loop through `loop_end`), and the loop validation cases in
`ValidateFlowServiceTest`.

Acceptance, per the code:

- [x] Handler, engine and validation tests exist.
- [x] Missing LoopEnd, nested loops, an invalid `loop_node_id` and a reserved `iterator_name` are rejected (errors).
- [x] Sequential loops with the same `iterator_name` do not conflict (cleanup writes `null`).
- [x] A variable type or element-type conflict is rejected at publish with a clear message.
- [ ] While condition that never changes in the body gives a warning (8.5): not built.
- [ ] Manual runs (counted 5 photos end to end, while exit, a 60-iteration loop under the lock heartbeat) and the
  performance check (100 iterations x 5 nodes): not recorded.
- [ ] A batch loop at the iteration-budget boundary: behaviour pending decision 14.1.

---

## 16. Related documents

- [../README.md](../README.md) - Flow Engine spec index
- [../01-state-model.md](../01-state-model.md) - namespaces, contact addressing
- [../06-validation.md](../06-validation.md) - validation layers
- [../node-usage-statistics.md](../node-usage-statistics.md) - node usage statistics
- [02-input.md](02-input.md) - the "Store as list" variable on an input in a loop body
