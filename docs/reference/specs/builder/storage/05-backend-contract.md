# 05 · Backend variable contract and resolver

**Layer:** backend (PHP)

## Purpose

One service turns the UI shape `{ name, storage, group, type }` into the physical state path. Every
handler with a "save user data" concept (Input, SendMessage button `save_to_variable`, Assign, Call)
uses it. The resolver is symmetric: the same `Variable` maps to the same path for writing and reading.

## Contract

```php
namespace App\Domains\Flow\State\Variables;

final readonly class Variable
{
    public function __construct(
        public string $name,                  // identifier: [A-Za-z_][A-Za-z0-9_]*
        public VariableStorage $storage,      // enum: Contact | Session
        public ?string $group = null,         // one level; contact storage only
        public ?VariableType $type = null,    // enum; null = undeclared, no coercion
        public array $properties = [],        // e.g. max_size / item_type for arrays
    ) {}

    public static function tryFromArray(array $raw): ?self;  // null when name/storage missing
}
```

`Variable.type` is the `VariableType` enum (see 10 for the full list and coercion). Unknown type strings
from old snapshots are ignored (type becomes `null`) so flows stay loadable.

```php
namespace App\Domains\Flow\Contracts;

interface VariableResolverInterface
{
    /** Contact + no group -> contact.{name}; Contact + group -> contact.{group}.{name}; Session -> flow.{name}. */
    public function resolveTargetPath(Variable $variable): string;

    /** Reads through the engine's ScopedStateReader carried by the context; coerces by declared type. */
    public function read(Variable $variable, NodeExecutionContext $context): mixed;

    /** Parses a legacy `save_to` string ("flow.foo", "contact.foo", "contact.bar.foo", "foo"); deeper paths throw. */
    public function fromLegacyPath(string $path): Variable;
}
```

`NodeExecutionContext` is `Fapost\Foundation\DTO\NodeExecutionContext`. `read()` returns `null` when no
reader is attached or the path is empty. `VariableResolver` is bound as scoped (it holds the tenant's schema
registry) and never writes: writes go through `ScopedStateWriter` / `ContactWriter` using the returned path.
`fromLegacyPath()` is part of the contract but handlers currently parse legacy `save_to` themselves.

## Reserved names

`Variable` rejects the names `id`, `channel_id`, `tenant_id`, `external_id`, `meta`, `language`,
`is_blocked`, `created_at`, `updated_at` (aligned with `ContactWriter`) and the group `meta`.
A group on a session variable is rejected.

## Validation

`FlowGraphStructureValidator` (audit only for now) checks `input`, `send_message` and `assign` configs:

- the new shape (`variable` / `save_to_variable` / `operations`) and the legacy shape (`save_to`, `target`+`key`)
  cannot be defined together;
- the new shape must construct a valid `Variable`;
- within one Assign node the (`storage`, `group`, `name`) of `operations[*].variable` are unique;
- Branch rules with a `user_variable` left side must carry a valid variable.

## Handlers and legacy snapshots

| Handler | New field | Legacy |
|---------|-----------|--------|
| `InputNodeHandler` | `variable` | `save_to` |
| `SendMessageNodeHandler` | `save_to_variable` | `save_to` + `save_to_type` |
| `AssignNodeHandler` | `operations[*].variable` | `target` + `key` |

Each handler branches once: new shape goes through the resolver, otherwise the legacy path. No data
migration is needed; a legacy snapshot is rewritten to the new shape on the next builder autosave.

## Files

- `app/Domains/Flow/State/Variables/{Variable,VariableStorage,VariableType,VariableResolver}.php`
- `app/Domains/Flow/Contracts/VariableResolverInterface.php`
- `app/Domains/Flow/Providers/FlowServiceProvider.php` — bindings
- `app/Domains/Flow/Validation/FlowGraphStructureValidator.php`
- `app/Domains/Flow/Handlers/{Input,SendMessage,Assign,Call}NodeHandler.php`
- Tests: `tests/Unit/Domains/Flow/State/Variables/VariableResolverTest.php`

## Related

- [10-variable-type-coercion](10-variable-type-coercion.md)
