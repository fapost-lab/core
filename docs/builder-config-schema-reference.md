# Builder · `configSchema()` reference

Single source of truth for the schema returned by every `NodeHandlerInterface::configSchema()`. The Vue builder's
generic renderer (`SchemaConfigRenderer.vue` → `SchemaFields.vue`) reads this shape directly — when a handler skips an
inline-styled override (SendMessage / Input / Condition / Trigger), the entire right-pane config form is built from this
array.

Keep this file in sync with `resources/js/builder/components/editor/config/SchemaFields.vue#FIELD_COMPONENTS`. If you
add a new field type to the renderer, extend the matching section here in the same PR.

---

## 1. Overview

Two equivalent ways to express a schema — the fluent builder from `fapost/support` (recommended) or the raw array. The
renderer reads the same wire shape in both cases.

**Fluent (preferred):**

```php
use FAPost\Support\Builder\Schema\Field;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;

public function configSchema(): array
{
    return Schema::make()
        ->section(
            Section::make('connection', 'Connection')
                ->icon('globe-alt')
                ->fields([
                    Field::string('url')->label('URL')->required(),
                    Field::number('timeout')->label('Timeout')->default(10)->min(1)->max(300),
                ]),
        )
        ->section(
            Section::make('advanced', 'Advanced')
                ->icon('cog-6-tooth')
                ->collapsed()
                ->fields([
                    Field::keyValue('headers')->label('Custom headers'),
                ]),
        )
        ->toArray();
}
```

**Raw array (still supported):**

```php
public function configSchema(): array
{
    return [
        'sections' => [
            ['key' => 'connection', 'label' => 'Connection', 'icon' => 'globe-alt', 'fields' => ['url', 'timeout']],
            ['key' => 'advanced',   'label' => 'Advanced',   'icon' => 'cog-6-tooth', 'fields' => ['headers'], 'collapsed' => true],
        ],
        'url'     => ['type' => 'string', 'label' => 'URL', 'required' => true],
        'timeout' => ['type' => 'number', 'label' => 'Timeout', 'default' => 10, 'min' => 1, 'max' => 300],
        'headers' => ['type' => 'key-value', 'label' => 'Custom headers'],
    ];
}
```

The schema is serialized into the node-handler registry payload, sent to the builder on flow load, and rendered through
the Vue components in `resources/js/builder/components/editor/config/`. There is no separate JSON-schema validator —
the renderer is permissive and falls back to `TextField` for unknown types, the backend validator
(`FlowDefinitionValidator`) is the contract for runtime correctness.

---

## 2. Top-level keys

| Key                       | Type             | Purpose                                                                               |
|---------------------------|------------------|---------------------------------------------------------------------------------------|
| `required`                | `array<string>`  | Marks listed field keys as required (red asterisk). Merged with per-field `required`. |
| `sections`                | `array<Section>` | UI grouping — each section becomes an accordion in the config panel. See §2.1.        |
| anything else with `type` | `FieldSchema`    | A field declaration. The key becomes the `node.config[key]` path.                     |

Keys that are not arrays-with-a-`type`, not `required`, and not `sections` are ignored.

### 2.1 `sections`

```php
'sections' => [
    [
        'key'       => 'connection',         // unique within the schema; used as Vue key
        'label'     => 'Connection',         // shown on the accordion header
        'icon'      => 'globe-alt',          // optional, Heroicon name (see §2.2)
        'fields'    => ['url', 'timeout'],   // ordered list of field keys to render in this section
        'collapsed' => false,                // optional, default false — start expanded
    ],
]
```

- Sections are rendered in array order. Field keys not declared in any section are dropped (intentional, so authors see
  visually where each field lives).
- If `sections` is missing entirely, the renderer falls back to a single auto-section labelled "Configuration"
  containing every declared field.
- A `Meta` section with the node id is always appended by the renderer — handlers don't declare it.

### 2.2 Section icons

Currently registered Heroicon names (see `resources/js/builder/components/editor/config/sectionIcons.ts`):

`globe-alt`, `arrow-down-tray`, `cog-6-tooth`, `book-open`, `magnifying-glass`, `adjustments-horizontal`, `bolt`,
`cube`, `arrow-right-circle`, `play`, `clock`, `flag`, `square-3-stack-3d`.

Unknown names fall back to a neutral square — extend `sectionIcons.ts` rather than passing arbitrary SVG.

---

## 3. Field types

Common props (apply to every type unless noted): see §4. Conditional visibility: see §5. Inline validators: see §6.

### 3.1 `string`

Single-line text input. Renders `TextField.vue` with `VariablePicker` for `{{path}}` insertion.

| Key             | Type       | Description                                     |
|-----------------|------------|-------------------------------------------------|
| `type`          | `'string'` | required                                        |
| `placeholder`   | `string`   | input placeholder                               |
| `regex`         | `string`   | inline-validated regex (Phase 2, see §6)        |
| `regex_message` | `string`   | error text shown under input when `regex` fails |

```php
// Fluent
Field::string('event_type')
    ->label('Event type')
    ->placeholder('sales.order.created')
    ->required()
    ->regex('^[a-z][a-z0-9_.]*$', 'Lowercase letters, digits, underscores, dots');

// Raw
'event_type' => [
    'type'          => 'string',
    'label'         => 'Event type',
    'placeholder'   => 'sales.order.created',
    'required'      => true,
    'regex'         => '^[a-z][a-z0-9_.]*$',
    'regex_message' => 'Lowercase letters, digits, underscores, dots',
],
```

### 3.2 `text`

Multi-line textarea. Renders `TextareaField.vue` with `VariablePicker`.

Same prop set as `string` plus a default of 4 rows of height. No regex validator at the moment.

```php
Field::text('message')->label('Message text')->placeholder('Welcome, {{contact.name}}');

// Raw
'message' => ['type' => 'text', 'label' => 'Message text', 'placeholder' => 'Welcome, {{contact.name}}'],
```

### 3.3 `number`

Numeric input. Renders `TextField.vue` with `type="number"` and no `VariablePicker` (templates don't substitute into
numeric fields).

| Key   | Type     | Description                              |
|-------|----------|------------------------------------------|
| `min` | `number` | inline-validated lower bound (inclusive) |
| `max` | `number` | inline-validated upper bound (inclusive) |

```php
Field::number('timeout')->label('Timeout (s)')->default(10)->min(1)->max(300);

// Raw
'timeout' => ['type' => 'number', 'label' => 'Timeout (s)', 'default' => 10, 'min' => 1, 'max' => 300],
```

### 3.4 `boolean`

Checkbox toggle. Renders `ToggleField.vue`.

```php
Field::toggle('remove_keyboard_after_press')->label('Remove keyboard after press')->default(true);

// Raw
'remove_keyboard_after_press' => ['type' => 'boolean', 'label' => 'Remove keyboard after press', 'default' => true],
```

### 3.5 `enum`

Dropdown select. Renders `SelectField.vue`.

| Key       | Type                                       | Description                                                    |
|-----------|--------------------------------------------|----------------------------------------------------------------|
| `options` | `array<string>` or `array<string, string>` | List of values, or value→label map. `values` is also accepted. |

```php
Field::select('method')->label('HTTP method')->options(['GET' => 'GET', 'POST' => 'POST'])->default('POST');

// Raw
'method' => [
    'type' => 'enum', 'label' => 'HTTP method',
    'options' => ['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT'], 'default' => 'POST',
],
```

### 3.6 `array`

List of strings. Renders `ArrayField.vue` — one input per item, `+ Add item` button, per-row delete.

```php
Field::array('include_state')->label('Include state')->help('State paths whose values are forwarded in the payload.');

// Raw
'include_state' => ['type' => 'array', 'label' => 'Include state', 'help' => '…'],
```

### 3.7 `json`

Raw JSON textarea. Renders `JsonField.vue` with `Victor Mono` font and inline parse-error display. Value is the parsed
object/array, not the raw string.

```php
Field::json('metadata')->label('Metadata')->default([]);

// Raw
'metadata' => ['type' => 'json', 'label' => 'Metadata', 'default' => []],
```

### 3.8 `state-picker`

Flow-state path input. Renders `StatePickerField.vue` — monospace input with `VariablePicker`.

```php
Field::statePicker('save_response_to')->label('Save response to')->placeholder('flow.webhook_response');

// Raw
'save_response_to' => ['type' => 'state-picker', 'label' => 'Save response to', 'placeholder' => 'flow.webhook_response'],
```

### 3.9 `key-value`

`Record<string, string>` editor — paired inputs, `+ Add` button. Renders `KeyValueField.vue`. Duplicate keys are flagged
inline (last-write-wins on commit). Empty keys are dropped on commit.

| Key           | Type                    | Description                                  |
|---------------|-------------------------|----------------------------------------------|
| `key_label`   | `string`                | header label above the key column            |
| `value_label` | `string`                | header label above the value column          |
| `placeholder` | `array{string: string}` | one entry used as placeholder for empty rows |

```php
Field::keyValue('headers')
    ->label('Custom headers')
    ->keyLabel('Header')
    ->valueLabel('Value')
    ->placeholder(['Authorization' => 'Bearer ...']);

// Raw
'headers' => [
    'type' => 'key-value', 'label' => 'Custom headers',
    'key_label' => 'Header', 'value_label' => 'Value',
    'placeholder' => ['Authorization' => 'Bearer ...'],
],
```

### 3.10 `object`

Nested group of fields under a single config key. Renders `ObjectField.vue` — recursively delegates to `SchemaFields`
with the sub-schema. State path is `config[key].subkey`; the parent emits the full sub-object on every change, no deep
merge in the store.

| Key        | Type                         | Description                                |
|------------|------------------------------|--------------------------------------------|
| `fields`   | `array<string, FieldSchema>` | sub-field definitions (same shape as root) |
| `required` | `array<string>`              | required sub-field keys                    |

```php
Field::object('transport_options')
    ->label('Transport options')
    ->fields([
        Field::number('retries')->label('Retries')->default(0),
        Field::toggle('verify_ssl')->label('Verify SSL')->default(true),
    ]);

// Raw
'transport_options' => [
    'type' => 'object', 'label' => 'Transport options',
    'fields' => [
        'retries' => ['type' => 'number', 'label' => 'Retries', 'default' => 0],
        'verify_ssl' => ['type' => 'boolean', 'label' => 'Verify SSL', 'default' => true],
    ],
],
```

`visible_when` paths inside an `object` resolve against the **root** config — use dot-notation
(`transport_options.retries`) to reach nested values from sibling fields.

### 3.11 `object-array`

Repeater of structured objects. Renders `ObjectArrayField.vue` — each item is a collapsible accordion with
`SchemaFields` for `item.fields`. Authors get `+ Add item`, per-item delete, and (optional) min/max bounds.

| Key         | Type     | Description                                                       |
|-------------|----------|-------------------------------------------------------------------|
| `item`      | `object` | `{ fields: ..., required?: ..., item_label?: '{key1} → {key2}' }` |
| `min_items` | `number` | minimum count, defaults to 0                                      |
| `max_items` | `number` | maximum count, defaults to unlimited                              |

The `item_label` template substitutes `{key}` for top-level item values — handy for the collapsed-row preview.

```php
Field::objectArray('result_mapping')
    ->label('Result mapping')
    ->minItems(0)
    ->maxItems(50)
    ->itemLabel('{from_path} → {to_state}')
    ->itemFields([
        Field::statePicker('from_path')->label('From response'),
        Field::statePicker('to_state')->label('To state'),
    ]);

// Raw
'result_mapping' => [
    'type' => 'object-array', 'label' => 'Result mapping',
    'min_items' => 0, 'max_items' => 50,
    'item' => [
        'item_label' => '{from_path} → {to_state}',
        'fields' => [
            'from_path' => ['type' => 'state-picker', 'label' => 'From response'],
            'to_state'  => ['type' => 'state-picker', 'label' => 'To state'],
        ],
    ],
],
```

---

## 4. Common field props

Available on every field declaration.

| Key            | Type                       | Description                                                                                 |
|----------------|----------------------------|---------------------------------------------------------------------------------------------|
| `type`         | `string`                   | Required — one of the types in §3. Unknown values fall back to `string`.                    |
| `label`        | `string`                   | Shown above the field. Defaults to the schema key.                                          |
| `help`         | `string`                   | Helper text rendered below the field, muted small.                                          |
| `default`      | `mixed`                    | Used when `node.config[key]` is `undefined`. Type-appropriate per field.                    |
| `placeholder`  | `string` or per-type shape | Field-type specific — see each type's section.                                              |
| `required`     | `bool`                     | Marks the field with a red asterisk. Equivalent to listing the key in top-level `required`. |
| `visible_when` | see §5                     | Conditional render expression.                                                              |

---

## 5. Conditional visibility — `visible_when`

A field can declare a condition that's evaluated against the root config; when it fails, the field is not rendered.
**The stored value stays in `node.config` even while hidden** — flipping the condition back restores the data without
loss. Runtime behaviour for hidden fields is the handler's responsibility (typically: ignore unless parent toggle is
on).

Short form (equality, AND of all clauses):

```php
'success_statuses' => [
    'type'         => 'array',
    'label'        => 'Custom success statuses',
    'visible_when' => ['transport_options.success_when' => 'custom'],
],
```

Verbose form (multiple operators):

```php
'visible_when' => [
    ['field' => 'mode', 'op' => 'in', 'value' => ['custom', 'advanced']],
    ['field' => 'enabled', 'op' => 'truthy'],
],
```

Supported operators: `equals` (default), `in` (value must be an array, actual must be one of), `truthy`.

Dot-paths reach into nested objects: `transport_options.retries`, `headers.Authorization`. Paths that don't resolve
evaluate as `undefined` (so `equals` only matches if `value` itself is `undefined`).

---

## 6. Field-level validators

Inline-only — backend `FlowDefinitionValidator` remains the source of truth for what blocks Publish.

| Type     | Props                    | Behaviour                                                           |
|----------|--------------------------|---------------------------------------------------------------------|
| `string` | `regex`, `regex_message` | Red rim + error text when regex fails. Empty value passes.          |
| `number` | `min`, `max`             | Red rim + error text when value is outside the range. Empty passes. |

Validators do not block saves — they're a UX cue so authors fix obvious typos before hitting Publish. Anything
load-bearing must live in the backend validator.

---

## 7. Reserved top-level keys

These keys can't be used as field names — they're consumed by the renderer for other purposes:

- `required` — top-level `array<string>` of required field keys.
- `sections` — top-level grouping.

A schema test (`tests/Unit/Domains/Flow/NodeHandlerSchemaSectionsTest.php`) enforces that `sections[*].fields` only
references declared field keys.

---

## 8. Migration notes

- **Pre-Phase-1 schemas** (flat field list, no `sections`) still render — the fallback "Configuration" section catches
  every declared field. No change required unless you want the new visual grouping.
- **`switch` field type** — removed. Use `condition` (branch handler) with multiple rules instead.
- **`save_to` (string) on SendMessage / Input** — superseded by `save_to_variable` / `variable` objects (
  `Variable` shape: `{ name, type, storage, group }`). Legacy `save_to` still accepted by the runtime; new authors
  should use the new shape.

---

## 9. Fluent builders (`fapost/support`)

The wire format from §2–§3 is generated by the `FAPost\Support\Builder\Schema` namespace. It's a thin layer over the
same shape — `toArray()` is the only contract — giving authors autocomplete, type-safe field-specific methods (only
`min()`/`max()` on `NumberField`, only `regex()` on `TextField`, etc.) and refactor-safety on a vocabulary that's
growing. Handlers may mix fluent and raw arrays during migration; the renderer can't tell the difference.

| Class / factory               | Returns            | Field-specific methods                                    |
|-------------------------------|--------------------|-----------------------------------------------------------|
| `Schema::make()`              | `Schema`           | `section()`, `fields()`, `required()`, `toArray()`        |
| `Section::make($key, $label)` | `Section`          | `icon()`, `collapsed()`, `fields()`                       |
| `Field::string($name)`        | `TextField`        | `regex(pattern, message?)`                                |
| `Field::text($name)`          | `TextareaField`    | —                                                         |
| `Field::number($name)`        | `NumberField`      | `min()`, `max()`                                          |
| `Field::select($name)`        | `SelectField`      | `options(array)`                                          |
| `Field::toggle($name)`        | `ToggleField`      | —                                                         |
| `Field::array($name)`         | `ArrayField`       | —                                                         |
| `Field::json($name)`          | `JsonField`        | —                                                         |
| `Field::statePicker($name)`   | `StatePickerField` | —                                                         |
| `Field::keyValue($name)`      | `KeyValueField`    | `keyLabel()`, `valueLabel()`                              |
| `Field::object($name)`        | `ObjectField`      | `fields(array)`                                           |
| `Field::objectArray($name)`   | `ObjectArrayField` | `itemFields()`, `itemLabel()`, `minItems()`, `maxItems()` |

Common methods on every field: `label()`, `help()`, `default()`, `placeholder()`, `required(bool = true)`,
`visibleWhen(array)`.

---

## 10. Where the renderer lives

| File                                                                     | Role                                   |
|--------------------------------------------------------------------------|----------------------------------------|
| `resources/js/builder/components/editor/config/SchemaConfigRenderer.vue` | Section grouping + Meta accordion      |
| `resources/js/builder/components/editor/config/SchemaFields.vue`         | Per-field loop (recursion entry point) |
| `resources/js/builder/components/editor/config/sectionIcons.ts`          | Heroicon name registry                 |
| `resources/js/builder/components/editor/config/fields/*.vue`             | Per-type field components              |
| `resources/js/builder/composables/useFieldVisibility.ts`                 | `visible_when` resolver                |
| `packages/fapost-support/src/Builder/Schema/`                            | Fluent PHP builders (see §9)           |
