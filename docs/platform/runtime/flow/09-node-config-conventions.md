# 09. Node Config Conventions

One style for every new node, so that:

- `config` is predictable,
- transition handles stay stable,
- state keys do not turn into chaos,
- validation errors have one format.

Examples below use the registered Core types (see
[10-registered-nodes-catalog.md](10-registered-nodes-catalog.md)). The legacy types `condition`,
`switch`, `webhook`, `set_attribute` and `notify_staff` are **not** registered; `my_custom_node` is a
placeholder for a hypothetical vendor node.

---

## 1) Basic naming rules

### `type`

- snake_case, short and meaningful.
- Start with a verb when the node performs an action: `send_message`, `set_tag`, `emit_event`,
  `auth_request`.
- For plain logic a noun is fine: `branch`, `delay`, `loop`, `end`.

### `config` fields

- snake_case.
- Name what it is, not how it is used:
    - `timeout_seconds`, not `timeout` (when the unit matters),
    - a named variable target (`variable`, `save_to_variable`), not `var`.
- Start booleans with `is_` / `has_` where it reads better (`is_required`, `has_fallback`).

### transition handles (`sourceHandle`)

- only stable strings, never generated dynamically (button ids and rule ids are stable ids chosen by the author).
- recommended base set:
    - `default`
    - `success` / `error`
    - `invalid`
    - `timeout`
    - `no_response`

The Core handlers use `default`, `success`, `error`, `invalid`, `no_response`, `not_found`, `failed`,
`cancelled` and `loop`.

---

## 2) `config` format: hard requirements

`config` must be:

- JSON-serialisable,
- free of runtime objects and classes,
- backward-compatible within one node version.

### Normalisation in the handler

In `execute()` always:

1. `is_array($nodeConfig['config'] ?? null) ? ... : []`
2. extract values and check their types strictly,
3. apply defaults only where that is actually safe.

### Units

If a field is about time or size, put the unit in the name:

- `delay_seconds`
- `max_length`
- `retry_limit`

---

## 3) `configSchema()` conventions

`configSchema()` is the contract between backend and builder. It is built with the fluent builder
API `Fapost\Support\Builder\Schema` (`Schema` / `Section` / `Fields\*`), not raw arrays;
`Schema::toArray()` produces exactly the wire format the Vue renderer understands. A real example is
`SetTagNodeHandler` (`app/Domains/Flow/Handlers/SetTagNodeHandler.php`):

```php
return Schema::make()
    ->required(['action'])
    ->section(
        Section::make('action', (string) __('builder.nodes.set_tag.section'))
            ->icon('tag')
            ->fields([
                SelectField::make('action')
                    ->label((string) __('builder.nodes.set_tag.action'))
                    ->required()
                    ->default(TagAction::Add->value)
                    ->options(TagAction::options()),
                ArrayField::make('tags')
                    ->label((string) __('builder.nodes.set_tag.tags'))
                    ->help((string) __('builder.nodes.set_tag.tags_help')),
            ]),
    )
    ->toArray();
```

Per field, give at least:

- the name (`Field::make('name')`),
- `->label(...)`,
- `->required()` when the field is required.

Where it helps, add:

- `->default(...)`
- `->placeholder(...)`
- `->options([...])` (select-like fields)

### Available field types (`Fapost\Support\Builder\Schema\Fields`)

`TextField`, `TextareaField`, `NumberField`, `ToggleField`, `SelectField`, `ArrayField`,
`ObjectField`, `ObjectArrayField`, `KeyValueField`, `JsonField`, `DurationField`, `StatePickerField`,
`FlowPickerField`, `EnumCardsField`. Specialised UI (keyboards, multilingual text, and so on) still
needs a custom override component in the builder (see section 13 of
[06-node-development-guide.md](06-node-development-guide.md)). Core overrides exist for
`send_message`, `input`, `branch`, `assign`, `call`, `set_tag`, `notify`, `auth_request`, `loop` and
`loop_end`.

---

## 4) State key conventions

### General rules

- No magic strings for system keys.
- Use constants for `system.*` (`SystemStateKeys::*`; `rag.*` has `RagStateKeys::*`).
- Give `flow.*` keys a predictable structure.

### Pattern for `flow.*`

- `flow.<domain>.<field>` when the node writes structured data.
- Example:
    - `flow.user.email`
    - `flow.order.total`

When the node writes to a path from its config (a `variable` target), validate that it belongs to an
allowed namespace.

### Forbidden

- writing to the root without a namespace (`"email" => ...`),
- mixing different meanings in one key (`flow.tmp` as "everything"),
- using `module.*` as a place to write from Core nodes.

---

## 5) Contact mutation conventions

For changes outside session state the handler calls
`ContactWriterInterface::write(string $path, mixed $value)`, available as `context->contactWriter`
(the engine builds one `ContactWriter` per node execution). The legacy `effects[]` mechanism is
gone; `NodeExecutionResult` has no such field.

Rules enforced by `App\Domains\Flow\State\Writers\ContactWriter`:

- the `path` starts with `contact.`, otherwise `InvalidArgumentException`.
- depth is one level of grouping at most: `contact.<key>` or `contact.<group>.<key>`; deeper paths
  throw `InvalidArgumentException`.
- **reserved keys** (`RESERVED_COLUMNS`): `id`, `tenant_id`, `external_id`, `platform`; plus the
  reserved group `meta` (`contact.meta.*`). Writing them throws `ReservedContactPathException`.
- **writable canonical columns**: `language` and `is_authenticated`. They do not accept a nested
  group.
- everything else lands in `contacts.attributes` (JSON).
- a leaf and a group on the same path (`contact.profile` vs `contact.profile.name`) throw
  `StructuralPathConflictException`.
- array-typed variables (per the tenant variable schema) append with a circular buffer instead of
  replacing.
- `ReservedContactPathException` and `StructuralPathConflictException` live in
  `app/Domains/Flow/State/Exceptions/` and extend `RuntimeException`.
- every write commits immediately in its own transaction, so a handler must not rely on atomicity
  with the session-state save.
- document which `contact.*` paths the node writes, in its PHPDoc or node spec.

Reserved-key, depth and path-conflict rules are runtime guards in `ContactWriter`; they are not
checked when the flow is saved.

---

## 6) Validation errors and exceptions

### Principle

- Errors read the same way in debugging and in API responses.

### Message text

Format:

- `<node_type>: <short reason>`

Examples:

- `send_message: missing content_type`
- `call: missing url`
- `my_custom_node: invalid retry_limit`

### Where to throw

- In the handler, on an invalid config (`InvalidNodeConfigException`).
- Do not hide a critical configuration error behind `Waiting`.

Save-time validation is a different channel: `ValidateFlowService` returns `FlowValidationErrorDto`
(`path`, `code`, `message`); see
[06-validation.md](../../../reference/specs/flow-engine/06-validation.md).

---

## 7) Config versioning conventions

### What is breaking for config

- a field became required with no default,
- a field's type changed (`string` -> `array`),
- an existing field's meaning changed,
- handles that the graph depends on changed.

In those cases bump `version`.

### What is usually not breaking

- adding a new optional field,
- better internal normalisation with the same semantics.

---

## 8) Handle and edge conventions

### Required transitions

If the node needs specific outputs, record them:

- in the node's documentation,
- in validator tests.

### Practice

- never rename a handle within one node version,
- never use localised strings as handles,
- never encode a business payload in a handle (`status_123`).

---

## 9) Documentation template for each new node

For a new node add a short block (in the PR or the docs):

- `type`, `version`
- the node's purpose
- the `config` structure (fields, types, required)
- the possible `sourceHandle`s
- which `stateChanges` it writes
- which `contact.*` paths it writes through `ContactWriter` (if any)
- the retry / idempotency strategy

---

## 10) Mini checklist before merge

- [ ] `config` fields are snake_case and clear.
- [ ] `configSchema()` is built with the fluent `Schema` / `Section` / `Fields\*` API; fields have `->label()` / `->required()`.
- [ ] `sourceHandle`s are stable and documented.
- [ ] `system.*` keys go through constants.
- [ ] Handler errors use the `<node_type>: <reason>` format.
- [ ] Checked: the change does not break existing definitions of the current version.
