# 10 · Variable type coercion + schema registry

**Layer:** backend (PHP) + Filament admin
**Depends on:** 02, 04, 05 (Variable VO, VariableResolver, node config format)

## Context

`Variable.type` is meaningful at read time. Storage stays JSONB-friendly (native JSON values), but
`VariableResolver::read()` coerces the stored value to the type the variable declared. Without a
declared type the raw value is returned (backward compatible).

## Coercion contract

`VariableCoercerInterface::coerce(mixed $value, VariableType $type): mixed`, implemented by
`VariableCoercer` (pure, no I/O, singleton). `''` and `null` become `null` for every type.

| Type | Rule |
|------|------|
| `text`, `phone`, `email`, `select` | scalar to string; non-scalar to `null` |
| `number` | numeric string to `int` (no dot) or `float`; non-numeric to `null` |
| `confirm`, `boolean` | truthy `true,1,yes,y,on,да`; falsy `false,0,no,n,off,нет`; case-insensitive; other to `null` |
| `date` | `Carbon::parse()`, failure to `null` |
| `contact`, `file`, `photo`, `location`, `json`, `array` | as-is (structured values are written by platform handlers) |

`VariableResolver::read()` takes the type from the variable's own annotation and falls back to the
tenant schema registry when the variable carries none; with neither, it returns the raw value.

## Schema registry

### Table `tenant_variable_schema` (tenant scope)

Migrations: `2026_05_14_000001_create_tenant_variable_schema_table.php` and
`2026_06_10_000001_add_properties_to_tenant_variable_schema.php`.

| Column | Notes |
|--------|-------|
| `id` | uuid (ULID), PK |
| `tenant_id` | uuid |
| `storage` | `contact` or `session` |
| `group` | nullable (root) |
| `name` | variable name |
| `type` | value of `VariableType` |
| `properties` | jsonb, default `{}`; type-specific metadata (arrays: `max_size`, `item_type`) |
| `declared_in_flow_id` | nullable uuid, indexed, no database FK |
| `declared_by_node_id` | nullable string |
| `updated_at` | timestamptz |

Unique on (`storage`, `group`, `name`). Session variables are registered too, so Branch can coerce them.
There is no cascade cleanup: the row of a removed flow stays (the variable still exists in contact
attributes) until another publish re-declares it.

### Built on publish

`PublishFlowService::publish()` runs inside its transaction, after the draft is copied to
`flow_definitions`:

1. `VariableSchemaCollector::collect()` extracts declarations from `input` (`config.variable`),
   `assign` (`config.operations[*].variable`) and `send_message` (`config.save_to_variable`).
2. `assertNoVariableTypeConflicts()` compares each declaration with the existing row owned by a
   different flow. A different `type` yields `variable_type_conflict` (message from
   `VariableTypeConflictException`: `Variable "{path}" is declared as "{existing}" in flow "{name}", cannot redeclare as "{new}"`).
   For `array` variables with the same base type, differing `item_type` yields `variable_properties_conflict`.
   `max_size` is not compared because it is not carried in node config. Errors are raised as
   `FlowValidationException`, so publish is blocked. Re-publishing a flow's own variables may change their type.
3. `upsertVariableSchema()` upserts `type`, `properties`, `declared_in_flow_id`, `declared_by_node_id`.
4. After the transaction, `VariableSchemaRegistryInterface::invalidate()`.

### Registry and cache

`VariableSchemaRegistryInterface`: `get(storage, group, name): ?VariableType`,
`getAllForTenant()`, `getProperties(storage, group, name): array`, `invalidate()`.
`CacheBackedVariableSchemaRegistry` is a read-through cache under `tenant:{tenant_id}:variable_schema`
(entries are JSON `{type, properties}`, no TTL, write-through via `invalidate()`), bound as scoped so the
loaded map never outlives a request or queue job.

## Consumers

- `BranchNodeHandler` reads operands through `VariableResolver::read()` (via `OperandResolver`), so
  `confirm == true` compares booleans and `number` comparisons are numeric.
- `ContactWriter` consults the registry for array variables (`max_size`, append-only writes).
- `ContactInfolistSchema` (contact card, see 08) formats values by registry type.

## Files

- `app/Domains/Flow/State/Variables/{Variable,VariableType,VariableStorage,VariableResolver,VariableCoercer,CacheBackedVariableSchemaRegistry}.php`
- `app/Domains/Flow/Contracts/{VariableCoercerInterface,VariableResolverInterface,VariableSchemaRegistryInterface}.php`
- `app/Domains/Flow/Models/VariableSchemaEntry.php`
- `app/Domains/Flow/Services/{VariableSchemaCollector,PublishFlowService}.php`
- `app/Domains/Flow/Exceptions/VariableTypeConflictException.php`

## Out of scope

- Special UI for type conflicts (publish returns validation errors only).
- Coercion of a Branch right-hand operand that is a template.
- Type inference from values; only explicit declarations count.
- Per-tenant localisation of yes/no vocabularies (en/ru/uk literals are fixed in the coercer).

## Related

- [05-backend-contract](05-backend-contract.md), [07-branch-source-picker](07-branch-source-picker.md), [08-contact-card-groups](08-contact-card-groups.md)
