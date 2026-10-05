# 06. Node Development Guide

A working guide for engineers who add nodes to the flow engine. A new node must be:

- correct at runtime,
- compatible with existing flow definitions,
- safe under retry and concurrency,
- understandable in the builder UI.

---

## 1) Mental model: what a node is

A node is four parts:

1. **Definition JSON** (`flow_definitions.nodes[]`) - the node's data in the graph.
2. **Handler** (PHP class) - runs the node's logic.
3. **Edges** (`flow_definitions.edges[]`) - transitions to the next nodes.
4. **Builder config UI** (Vue) - edits `node.config`.

The engine runs only what is in the session's pinned definition snapshot (`flow_definition_id`).

---

## 2) The handler contract (backend)

Nodes implement `Fapost\Foundation\Contracts\NodeHandlerInterface`, normally by extending
`Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler` (both live in the `fapost-foundation`
package).

What a handler must define:

- `TYPE` (constant): the string identifier, for example `send_message`.
- `version(): int`: the handler's current version.
- `execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult`.

Usually also overridden:

- `label(): string` (name in the builder),
- `category(): string` (palette group; the base class returns `'Core'`),
- `configSchema(): array` (field schema for the UI and for required-field validation).

`AbstractVersionedHandler` already gives `type()` (from `TYPE`), `supportedVersions()` (defaults to
`[version()]`) and default `label()` / `category()` / `configSchema()`.

---

## 3) How a handler reaches the runtime

`NodeHandlerRegistry` is a singleton for the whole Horizon worker, so it stores **class-strings**,
not instances: an instance would keep the scoped dependencies of whichever job built the registry
first, which is a cross-tenant leak (see ADR-0001,
`.ai/knowledge/adr/0001-node-handlers-built-per-scope.md`).

Core handlers are listed in `FlowServiceProvider::registerCoreNodeHandlers()`, which the
`NodeHandlerRegistry` singleton factory calls from `register()`:

```php
private function registerCoreNodeHandlers(NodeHandlerRegistry $registry): void
{
    $handlers = [
        SendMessageNodeHandler::class,
        InputNodeHandler::class,
        // ...
        LoopEndNodeHandler::class,
    ];

    foreach ($handlers as $handlerClass) {
        $registry->register($handlerClass);
    }
}
```

- `register(class-string)` builds a throwaway instance only to read `type()` / `version()` and
  keeps just the class.
- `resolve()` and `all()` build a new instance on **every call** through the
  `NodeHandlerFactoryInterface` port (`app/Domains/Flow/Contracts/`). The implementation,
  `ContainerNodeHandlerFactory` (`app/Infrastructure/Flow/`), resolves through the container, so it
  always hits the scoped bindings of the job that is running right now.
- Handlers take collaborators as ordinary constructor dependencies. Resolver closures in a
  constructor are forbidden by the architecture test
  `FlowRuntimeIsolationTest::test_node_handlers_do_not_take_resolver_closures`.
- `FlowServiceProvider::boot()` freezes the registry (`freeze()`) once the app has booted, except
  in the `testing` environment. Late registration is rejected. Solutions and plugins add their
  handlers before the freeze.

`NodeHandlerRegistry::register()` also checks that:

- there is no duplicate `type@version` key,
- `version()` is included in `supportedVersions()`.

A violation throws `LogicException` on the first resolve of the registry.

---

## 4) Node format in `flow_definitions.nodes`

Fields the runtime actually uses:

- `id: string`
- `type: string`
- `version: int` (a missing value is read as `1` by the engine)
- `config: object`

Optional:

- `label` (UI),
- `required_transitions` (no longer read by anything: the validator that did was removed),
- any UI-only fields the engine ignores.

### Example

```json
{
  "id": "f0f7b8e6-7fdf-4f44-9ccd-fd57e33a6d4e",
  "type": "input",
  "version": 1,
  "label": "Ask Email",
  "config": {
    "variable": { "name": "user_email", "storage": "session" }
  }
}
```

The entry node is **derived**, not stored: it is the single node with no incoming edge
(`FlowGraphResolver::resolveEntryNode()`).

---

## 5) Outputs and edges: how a node picks the next step

A handler does **not** return a `next_node_id`. It returns a `sourceHandle` in the
`NodeExecutionResult`. `FlowGraphResolver::resolveNextNode()` then looks for the edge whose `from`
equals the current node and whose `handle` equals `sourceHandle` (a missing `handle` counts as
`default`) and takes its `to`. The engine follows an edge only when the status is `Executed`.

### Practical rules

- The `sourceHandle` in the handler and the edge `handle` in the definition must match 1:1.
- Keep handle names stable (`default`, `success`, `error`, `invalid`, a button id, a rule id).
- `loop_end` is the exception: the engine navigates back to its `loop_node_id` without an edge.

---

## 6) NodeExecutionResult: what a handler can return

Statuses (`NodeExecutionStatus`):

- `Executed` - the node ran; follow `sourceHandle` to the next node.
- `Waiting` - wait for an inbound message or an external condition; the session becomes
  `waiting_input`.
- `Delayed` - a pause, in two forms (below).
- `Failed` - an execution error; the session becomes `failed`.
- `Finished` - an explicit terminal result (`end` returns it).

`delayed()` has two forms:

- `NodeExecutionResult::delayed(resumeAt: $when)`: the session parks as `paused`, the engine stores a
  `system.delayed.{nodeId}.resume_at` marker and calls `DelayResumeSchedulerInterface::schedule()`
  after commit. At `resumeAt` (or on the first message after it) the node runs again with
  `NodeExecutionContext::$resumedAfterDelay = true`. Messages from the contact in the meantime are
  answered as busy.
- `NodeExecutionResult::delayed()` without `resumeAt`: there is no resume time to act on, so it parks
  like `waiting()` (`waiting_input`) and the contact's next message runs the node again.

The built-in `delay` node does not use `delayed()`: it schedules its own resume job through
`DelayResumeSchedulerInterface`, keeps its marker under `system.delay.{nodeId}` and returns
`waiting()`.

Result fields:

- `sourceHandle` - which edge to take.
- `stateChanges` - a flat map written into the session state (`flow.x`, `system.y`).
- `metadata` - technical data for logs and diagnostics (the `end` node puts its status here).
- `logResolved` - the values actually read, for `flow_logs.resolved`.
- `errorMessage` - for `Failed`.
- `resumeAt` - for `Delayed`.

The result carries no messages: handlers send messages themselves through `MessageSenderInterface`.

Mutations outside the session state (contact attributes, contact language) are done by the handler
directly through `ContactWriterInterface`, available as `NodeExecutionContext->contactWriter`
(see section 9). The legacy `effects[]` array is gone.

---

## 7) The node `config`

`node.config` is plain data that drives the handler.

- It must be JSON-serialisable.
- Normalise and validate it first thing in `execute()`.
- On an invalid config throw a domain error (`InvalidNodeConfigException`). The engine marks the
  session `failed` and rethrows.
- Do not assume the UI always sent a perfect payload.

### `configSchema()` and the builder

`NodeTypesController` returns to the UI `type`, `version`, `label`, `category`, `config_schema`
and a `palette` flag (`false` for auto-managed types, currently only `loop_end`).

The builder takes `config_schema` from the registry and renders a form. Even with a custom Vue
override the schema stays useful as a contract, and `ValidateFlowService` enforces the fields listed
in `configSchema()['required']`.

---

## 8) State changes and namespaces

The engine writes `stateChanges` into `flow_sessions.state` through `FlowSessionPersister`.

- Keys are flat (`system.language`, `flow.user_name`, ...).
- The persister expands them into the JSON tree.

### Namespaces

The canonical list is the enum `Fapost\Foundation\Flow\Enums\StateNamespace`: `system`, `flow`,
`rag`, `module`, `contact`, `call`. A new namespace is added only to that enum, never in a single node.

- `flow.*`, `call.*` - writable by any handler (user variables).
- `system.*`, `rag.*` - only whitelisted node types; the whitelist is `SystemStateNamespacePolicy`
  (`app/Domains/Flow/State/SystemStateNamespacePolicy.php`), applied in `FlowSessionPersister`.
  Today `system.*`: `send_message`, `input`, `delay`, `notify`, `set_tag`; `rag.*`: `rag_query`. A new
  writer is a deliberate whitelist edit. A violation throws `StateNamespaceViolationException`.
- `module.*` - read-only, read through `DataAccessorInterface`; never appears in `stateChanges`.
- `contact.*` - a derived projection, also not written through `stateChanges`: contact mutations go
  through `ContactWriter` (section 9).

### Practice

- Use the existing constants (`SystemStateKeys::*`, `RagStateKeys::*`) for system keys, not magic strings.
- Do not mix navigation and state: transitions come from `current_node_id` plus edges, not from JSON state.

---

### Variables

Nodes that save user data (`input`, `send_message.save_to_variable`, `assign.operations`) describe the
target as a `Variable` (name, storage, optional group, optional `type` from `VariableType`).
See [09-node-config-conventions.md](09-node-config-conventions.md).

---

## 9) Side effects: where the boundary is

A handler should be as deterministic as possible.

State mutations go through `stateChanges` (section 8) and pass `FlowSessionPersister` and the
`SystemStateNamespacePolicy` whitelist.

Mutations outside session state (contact attributes, contact language, ...) the handler performs
**directly** through `Fapost\Foundation\Flow\Contracts\ContactWriterInterface`, passed in
`NodeExecutionContext->contactWriter`. Writes are immediate. The writer rejects reserved contact
keys and structural path conflicts at write time (see section 5 of
[09-node-config-conventions.md](09-node-config-conventions.md)).

Tags are a separate table, so `SetTagNodeHandler` goes through `ContactTagRepositoryInterface`
instead of the writer.

---

## 10) Concurrency, retry and idempotency

A node must be safe to run again.

Why:

- a job can be retried,
- a lock may not be acquired and the job moves on by backoff,
- an optimistic-lock conflict can make the orchestration retry.

The engine gives each node run an idempotency key: `{updateId}|{sessionId}` when an inbound message
is being processed, otherwise `{sessionId}:{nodeId}:{session.version}`. It is exposed as
`NodeExecutionContext->idempotencyKey`.

### Basic techniques

- Store "already done" in state (as `send_message` does with `system.sent_messages`).
- Use stable idempotency keys for external calls.
- Do not perform irreversible operations without duplicate protection.

---

## 11) Node versioning (critical)

- **Backward-compatible** change -> keep the same version.
- **Breaking** change -> a new handler version.

A session is pinned to one `flow_definition_id`, hence to the `type` + `version` of its nodes.
Never silently change the meaning of an old version in a way that breaks existing flows. Old
versions stay registered. See `docs/site/extending/flow-nodes/versioning.mdx` and the
registry rules in `.ai/knowledge/domains/flow/RULES.md`.

---

## 12) Definition validation

Two classes validate a definition, and they behave differently. Details:
[docs/reference/specs/flow-engine/06-validation.md](../../../reference/specs/flow-engine/06-validation.md).

**`ValidateFlowService`** is what the builder and publish use. It returns a list of
`FlowValidationErrorDto` (`path`, `code`, `message`) instead of throwing. It checks, per node, a
registered `type@version`, the required config fields from `configSchema()`, the node-specific rules
(branch rules, `emit_event`, `end`, `rag_query`, `input` save target, `subflow`, `loop` / `loop_end`,
media, module references), and across the graph: terminal `end` nodes, nested loops, keyboard
`send_message` terminal rules, button edges and the subflow call graph. Save-draft does **not**
validate; validation runs on an explicit validate request and atomically on publish.

**`FlowGraphStructureValidator`** is a structural validator that returns the same
`FlowValidationErrorDto` list and never throws: no duplicate node ids, edges point at existing nodes,
no duplicate handle on one node, exactly one derived entry node, no orphans, and the variable contract
(`variable_contract_*`, `branch_rules_*`). The save / validate / publish pipeline does not call it yet;
only `php artisan flow:audit-graph` does. See `docs/reference/specs/flow-engine/06-validation.md`.

Neither validates "at least one `end` node".

---

## 13) Builder / UI: what to add for a new node

Minimum:

1. Register the handler in the backend registry.
2. Make sure `NodeTypesController` returns the new type.
3. Add a config component to the builder (or rely on the default renderer).
4. Update the node-card preview if needed.

`ConfigPanel.vue` (`resources/js/builder/components/editor/ConfigPanel.vue`) has an `OVERRIDES` map
of Core components for some types: `send_message`, `input`, `condition` / `branch`, `assign`, `call`,
`set_tag`, `notify`, `auth_request`, `loop`, `loop_end`. Resolution order: Core override -> vendor
override (`vendorConfigs`, Solution components through a Vite glob) -> the generic
`SchemaConfigRenderer`, which draws a form from `configSchema()`.

Recommendation:

- simple node -> live on the schema-driven form,
- complex node (multilingual text, keyboards, nested structures) -> write a custom override.

---

## 14) Step-by-step: add a new node

1. Design the `type`, `version`, the list of `sourceHandle`s and the `config` structure.
2. Implement the handler class in `app/Domains/Flow/Handlers/`.
3. Add a `configSchema()` with clear fields (and `required`).
4. Register the handler in `FlowServiceProvider::registerCoreNodeHandlers()` (not in `boot()`).
5. Add or update the UI config (Vue) in the builder.
6. If the node needs rules beyond required fields, add them to `ValidateFlowService`.
7. Write tests (see [08-node-test-matrix.md](08-node-test-matrix.md)):
    - handler tests (happy path, invalid config, edge cases),
    - validator and execution-path tests.
8. Check re-run behaviour (idempotency).
9. Document it: expected config, outputs, stateChanges, contact mutations through `ContactWriter`;
   add a row to [10-registered-nodes-catalog.md](10-registered-nodes-catalog.md).

---

## 15) Anti-patterns

- Do not return unstable or random `sourceHandle`s.
- Do not write magic strings into `system.*` without constants.
- Do not mix UI presentation and runtime config in one field without a reason.
- Do not make a handler depend on global mutable state or hold scoped dependencies statically.
- Do not change the semantics of an existing node version in a breaking way.
- Do not skip negative-path tests (broken config, empty fields, missing edges).

---

## 16) A quick template

`configSchema()` is built with the fluent builder API from `Fapost\Support\Builder\Schema`
(`Schema` / `Section` / `Fields\*`), not raw arrays; see section 7 in
[09-node-config-conventions.md](09-node-config-conventions.md) and the real example
`SetTagNodeHandler` (`app/Domains/Flow/Handlers/SetTagNodeHandler.php`). `my_custom_node` below is
a placeholder name for a hypothetical vendor node; it is not registered.

```php
final class MyCustomNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'my_custom_node';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Custom';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->required(['value'])
            ->section(
                Section::make('main', 'My Custom Node')
                    ->fields([
                        TextField::make('value')
                            ->label('Value')
                            ->required(),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $value = $config['value'] ?? null;

        if (! is_string($value) || '' === $value) {
            throw new InvalidNodeConfigException('my_custom_node: missing value');
        }

        return NodeExecutionResult::executed(
            sourceHandle: 'default',
            stateChanges: ['flow.my_custom_value' => $value],
        );
    }
}
```

Use it as a starting point and adapt it to the node's runtime needs.
