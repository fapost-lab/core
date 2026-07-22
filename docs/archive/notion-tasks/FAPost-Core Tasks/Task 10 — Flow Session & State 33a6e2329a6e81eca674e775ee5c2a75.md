# Task 10 — Flow Session & State

> Архив Notion. Актуальная документация: [[01-state-model]]


Depends on: 09
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 4
Status: Готово
Task №: 10

## Цель

Сформировать стабильный контракт хранения и резолва runtime state внутри Flow Engine:

- root namespace — стабильный системный контракт через enum
- write policy формализована по владельцу данных (4 категории)
- `FlowState` — typed wrapper над JSON state, не растворяется в resolvers
- `module.*` не физический JSON-кеш, а lazy accessor layer
- `StateReader` / `StateWriter` — публичный API для engine и нод

---

## Принципы

- **Регистрируются не переменные — регистрируются владельцы данных**
- Root namespace добавляется только при появлении нового owner / lifecycle / policy boundary
- `FlowState` работает только с session-backed namespaces (`system`, `flow`, `rag`). Не знает о `module`
- `StateReader` / `StateWriter` маршрутизируют через `NamespaceResolverRegistry` — это публичный API
- `module.*` резолвится lazy через accessor, никогда не пишется в `flow_sessions.state` физически
- Write policy enforce-ится внутри resolver через `WriteContext` — не снаружи

---

## Структура файлов

```
app/Domains/Flow/
├── State/
│   ├── StateNamespace.php              # enum: System, Flow, Rag, Module
│   ├── NamespaceWritePolicy.php        # enum: EngineOnly, NodeRestricted, Writable, AccessorOnly
│   ├── StatePath.php                   # value object: namespace + owner? + leaf
│   ├── FlowState.php                   # typed container для session-backed state
│   ├── WriteContext.php                # value object: кто пишет (engine/builder/node/accessor)
│   ├── StateReader.php                 # публичный read API, маршрутизирует через registry
│   ├── StateWriter.php                 # публичный write API, маршрутизирует через registry
│   ├── Exceptions/
│   │   ├── InvalidStatePathException.php
│   │   └── ReadonlyNamespaceException.php
│   └── Resolvers/
│       ├── NamespaceResolverInterface.php
│       ├── NamespaceResolverRegistry.php
│       ├── SessionStateResolver.php      # для system.* и flow.*
│       ├── RagStateResolver.php          # для rag.* (node-restricted write)
│       └── ModuleStateResolver.php       # для module.* → ModuleNamespaceRegistry → accessor
├── Contracts/
│   └── DataAccessorInterface.php        # уже заложен в Task 04, используется здесь
app/Domains/Shared/
└── Registries/
    └── ModuleNamespaceRegistry.php      # hr→HrAccessor, crm→CrmAccessor, etc.
```

**Миграция:**

```
database/migrations/tenant/2026_XX_XX_create_flow_sessions_table.php
```

---

## Полный код

### `StateNamespace.php`

```php
<?php

namespace App\Domains\Flow\State;

enum StateNamespace: string
{
    case System = 'system';
    case Flow   = 'flow';
    case Rag    = 'rag';
    case Module = 'module';

    /**
     * Root namespace добавляется только при появлении нового
     * owner / lifecycle / policy boundary — не под новые поля.
     * Потенциальные будущие: auth, temp, integration, analytics.
     */
    public function writePolicy(): NamespaceWritePolicy
    {
        return match($this) {
            self::System => NamespaceWritePolicy::EngineOnly,
            self::Flow   => NamespaceWritePolicy::Writable,
            self::Rag    => NamespaceWritePolicy::NodeRestricted,
            self::Module => NamespaceWritePolicy::AccessorOnly,
        };
    }
}
```

### `NamespaceWritePolicy.php`

```php
<?php

namespace App\Domains\Flow\State;

enum NamespaceWritePolicy
{
    /**
     * Пишет только engine internals.
     * system.started_at, system.current_node, system.retry_count
     */
    case EngineOnly;

    /**
     * Пишет только конкретная нода (rag_query).
     * rag.found, rag.confidence, rag.answer, rag.intent
     */
    case NodeRestricted;

    /**
     * Пишут builder и любые node handlers.
     * flow.name, flow.phone, flow.discount_percent
     */
    case Writable;

    /**
     * Пишет только через DataAccessorInterface.
     * module.hr.department — резолвится lazy, не хранится в JSON.
     */
    case AccessorOnly;
}
```

### `StatePath.php`

```php
<?php

namespace App\Domains\Flow\State;

use App\Domains\Flow\State\Exceptions\InvalidStatePathException;

final readonly class StatePath
{
    public function __construct(
        public StateNamespace $namespace,
        public ?string        $owner,  // обязателен только для module.*
        public string         $leaf,
    ) {}

    /**
     * Парсит строку вида "flow.name", "module.hr.department".
     *
     * Правила валидации:
     *   flow.*    → leaf обязателен, owner = null
     *   rag.*     → leaf обязателен, owner = null
     *   system.*  → leaf обязателен, owner = null
     *   module.*  → owner обязателен + leaf обязателен
     *
     * @throws InvalidStatePathException
     */
    public static function from(string $path): self
    {
        $segments = explode('.', $path);

        if (count($segments) < 2) {
            throw new InvalidStatePathException(
                "State path must have at least two segments, got: '{$path}'"
            );
        }

        $namespace = StateNamespace::tryFrom($segments[0])
            ?? throw new InvalidStatePathException(
                "Unknown root namespace '{$segments[0]}' in path '{$path}'"
            );

        if ($namespace === StateNamespace::Module) {
            // module.hr.department — минимум 3 сегмента
            if (count($segments) < 3) {
                throw new InvalidStatePathException(
                    "module namespace requires owner and leaf segment, got: '{$path}'"
                );
            }
            $owner = $segments[1];
            $leaf  = implode('.', array_slice($segments, 2));
        } else {
            $owner = null;
            $leaf  = implode('.', array_slice($segments, 1));
        }

        if ($leaf === '') {
            throw new InvalidStatePathException(
                "State path leaf cannot be empty in: '{$path}'"
            );
        }

        return new self($namespace, $owner, $leaf);
    }

    public function toString(): string
    {
        return $this->owner !== null
            ? "{$this->namespace->value}.{$this->owner}.{$this->leaf}"
            : "{$this->namespace->value}.{$this->leaf}";
    }
}
```

### `FlowState.php`

```php
<?php

namespace App\Domains\Flow\State;

/**
 * Typed container для session-backed state.
 * Работает только с namespaces: system, flow, rag.
 * Не знает о module — module.* резолвится через ModuleStateResolver → accessor.
 *
 * Хранится в flow_sessions.state (JSONB).
 * Отвечает за: сериализацию, nested get/set, единый контракт.
 */
final class FlowState
{
    public function __construct(
        private array $data = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Читает значение по StatePath.
     * Только для session-backed namespaces (system, flow, rag).
     * Для module.* — вызов через ModuleStateResolver, не напрямую.
     */
    public function get(StatePath $path): mixed
    {
        $keys  = array_merge([$path->namespace->value], explode('.', $path->leaf));
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Записывает значение по StatePath.
     * Write policy НЕ проверяется здесь — это ответственность resolver/StateWriter.
     */
    public function set(StatePath $path, mixed $value): void
    {
        $keys  = array_merge([$path->namespace->value], explode('.', $path->leaf));
        $ref   = &$this->data;

        foreach (array_slice($keys, 0, -1) as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }

        $ref[end($keys)] = $value;
    }
}
```

### `WriteContext.php`

```php
<?php

namespace App\Domains\Flow\State;

/**
 * Описывает кто инициирует запись в state.
 * Resolver использует context для enforcement write policy.
 *
 * Примеры:
 *   WriteContext::engine()          → пишет engine (system.*)
 *   WriteContext::node('rag_query') → пишет rag_query нода (rag.*)
 *   WriteContext::builder()         → пишет flow builder (flow.*)
 *   WriteContext::accessor('hr')    → пишет HR accessor (module.*)
 */
final readonly class WriteContext
{
    private function __construct(
        public string  $type,   // engine | node | builder | accessor
        public ?string $name,   // имя ноды или accessor-а
    ) {}

    public static function engine(): self
    {
        return new self('engine', null);
    }

    public static function node(string $nodeType): self
    {
        return new self('node', $nodeType);
    }

    public static function builder(): self
    {
        return new self('builder', null);
    }

    public static function accessor(string $moduleName): self
    {
        return new self('accessor', $moduleName);
    }

    public function isEngine(): bool   { return $this->type === 'engine'; }
    public function isNode(string $nodeType): bool { return $this->type === 'node' && $this->name === $nodeType; }
    public function isBuilder(): bool  { return $this->type === 'builder'; }
    public function isAccessor(): bool { return $this->type === 'accessor'; }
}
```

### `NamespaceResolverInterface.php`

```php
<?php

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\FlowState;
use App\Domains\Flow\State\StatePath;
use App\Domains\Flow\State\WriteContext;

interface NamespaceResolverInterface
{
    public function get(StatePath $path, FlowState $state): mixed;

    /**
     * Readonly и restricted реализации бросают ReadonlyNamespaceException.
     * Write policy enforce-ится внутри реализации через WriteContext.
     */
    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void;
}
```

### `NamespaceResolverRegistry.php`

```php
<?php

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\StateNamespace;

class NamespaceResolverRegistry
{
    /** @var array<string, NamespaceResolverInterface> */
    private array $resolvers = [];

    public function register(StateNamespace $namespace, NamespaceResolverInterface $resolver): void
    {
        $this->resolvers[$namespace->value] = $resolver;
    }

    public function for(StateNamespace $namespace): NamespaceResolverInterface
    {
        return $this->resolvers[$namespace->value]
            ?? throw new \LogicException("No resolver registered for namespace '{$namespace->value}'");
    }
}
```

### `SessionStateResolver.php`

```php
<?php

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\\{FlowState, NamespaceWritePolicy, StatePath, StateNamespace, WriteContext};
use App\Domains\Flow\State\Exceptions\ReadonlyNamespaceException;

/**
 * Resolver для system.* и flow.*.
 * system.* — engine-only (ReadonlyNamespaceException для всех кроме engine).
 * flow.*   — writable (любой WriteContext).
 */
class SessionStateResolver implements NamespaceResolverInterface
{
    public function __construct(
        private readonly StateNamespace $namespace,
    ) {}

    public function get(StatePath $path, FlowState $state): mixed
    {
        return $state->get($path);
    }

    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void
    {
        $policy = $this->namespace->writePolicy();

        if ($policy === NamespaceWritePolicy::EngineOnly && !$context->isEngine()) {
            throw new ReadonlyNamespaceException(
                "Namespace '{$this->namespace->value}' is engine-only. "
                . "Got write context type '{$context->type}'."
            );
        }

        $state->set($path, $value);
    }
}
```

### `RagStateResolver.php`

```php
<?php

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\\{FlowState, StatePath, WriteContext};
use App\Domains\Flow\State\Exceptions\ReadonlyNamespaceException;

/**
 * Resolver для rag.*.
 * NodeRestricted: пишет только нода 'rag_query'.
 * Всё остальное → ReadonlyNamespaceException.
 */
class RagStateResolver implements NamespaceResolverInterface
{
    private const ALLOWED_NODE = 'rag_query';

    public function get(StatePath $path, FlowState $state): mixed
    {
        return $state->get($path);
    }

    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void
    {
        if (!$context->isNode(self::ALLOWED_NODE)) {
            throw new ReadonlyNamespaceException(
                "Namespace 'rag' is node-restricted. Only 'rag_query' node can write. "
                . "Got: type='{$context->type}', name='{$context->name}'."
            );
        }

        $state->set($path, $value);
    }
}
```

### `ModuleStateResolver.php`

```php
<?php

namespace App\Domains\Flow\State\Resolvers;

use App\Domains\Flow\State\\{FlowState, StatePath, WriteContext};
use App\Domains\Flow\State\Exceptions\ReadonlyNamespaceException;
use App\Domains\Shared\Registries\ModuleNamespaceRegistry;
use App\Domains\Flow\Models\FlowSession;

/**
 * Resolver для module.*.
 * READ: lazy через accessor — не из FlowState JSON.
 * WRITE: только через accessor (WriteContext::accessor).
 *
 * module.hr.department → ModuleNamespaceRegistry → HrDataAccessor::get('department', ...)
 * НЕ сохраняется физически в flow_sessions.state.
 */
class ModuleStateResolver implements NamespaceResolverInterface
{
    public function __construct(
        private readonly ModuleNamespaceRegistry $registry,
        private readonly FlowSession             $session, // текущая сессия для контекста
    ) {}

    public function get(StatePath $path, FlowState $state): mixed
    {
        // $path->owner = 'hr', $path->leaf = 'department'
        $accessor = $this->registry->for($path->owner);

        return $accessor->get($path->leaf, $this->session->contact);
    }

    public function set(StatePath $path, mixed $value, FlowState $state, WriteContext $context): void
    {
        if (!$context->isAccessor()) {
            throw new ReadonlyNamespaceException(
                "Namespace 'module' is accessor-only. Direct write is forbidden. "
                . "Use DataAccessorInterface. Got context type '{$context->type}'."
            );
        }

        $accessor = $this->registry->for($path->owner);
        $accessor->set($path->leaf, $value, $this->session->contact);
    }
}
```

### `StateReader.php`

```php
<?php

namespace App\Domains\Flow\State;

use App\Domains\Flow\State\Resolvers\NamespaceResolverRegistry;

/**
 * Публичный read API для engine и нод.
 * Маршрутизирует через NamespaceResolverRegistry.
 * Принимает строку или StatePath.
 */
final class StateReader
{
    public function __construct(
        private readonly NamespaceResolverRegistry $registry,
        private readonly FlowState                 $state,
    ) {}

    public function get(string|StatePath $path): mixed
    {
        $statePath = $path instanceof StatePath ? $path : StatePath::from($path);
        $resolver  = $this->registry->for($statePath->namespace);

        return $resolver->get($statePath, $this->state);
    }
}
```

### `StateWriter.php`

```php
<?php

namespace App\Domains\Flow\State;

use App\Domains\Flow\State\Resolvers\NamespaceResolverRegistry;

/**
 * Публичный write API для engine и нод.
 * Маршрутизирует через NamespaceResolverRegistry.
 * WriteContext обязателен — resolver проверяет policy.
 */
final class StateWriter
{
    public function __construct(
        private readonly NamespaceResolverRegistry $registry,
        private readonly FlowState                 $state,
    ) {}

    public function set(string|StatePath $path, mixed $value, WriteContext $context): void
    {
        $statePath = $path instanceof StatePath ? $path : StatePath::from($path);
        $resolver  = $this->registry->for($statePath->namespace);

        $resolver->set($statePath, $value, $this->state, $context);
    }
}
```

### `ModuleNamespaceRegistry.php`

```php
<?php

namespace App\Domains\Shared\Registries;

use App\Domains\Flow\Contracts\DataAccessorInterface;

/**
 * Registry второго уровня для module.* namespace.
 * Хранит: owner → DataAccessorInterface
 * Регистрируется при boot через ModuleRegistrarInterface.
 *
 * hr  → HrDataAccessor
 * crm → CrmDataAccessor
 */
class ModuleNamespaceRegistry
{
    /** @var array<string, DataAccessorInterface> */
    private array $accessors = [];

    public function register(string $owner, DataAccessorInterface $accessor): void
    {
        $this->accessors[$owner] = $accessor;
    }

    public function for(string $owner): DataAccessorInterface
    {
        return $this->accessors[$owner]
            ?? throw new \LogicException("No accessor registered for module namespace '{$owner}'");
    }

    public function has(string $owner): bool
    {
        return isset($this->accessors[$owner]);
    }
}
```

### Миграция `flow_sessions`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id');
            $table->uuid('flow_id');              // logical flow UUID
            $table->uuid('flow_definition_id');   // FK на конкретный snapshot
            $table->integer('flow_version');
            $table->string('current_node_id')->nullable();

            // Namespaced JSON state: system.*, flow.*, rag.*
            // module.* НЕ хранится здесь — резолвится lazy через accessor
            $table->jsonb('state')->default('{}');

            // Статусы: pending, active, waiting_input, paused, completed, failed
            // expired — вычисляемое через expires_at, не отдельный статус
            $table->string('status')->default('pending');

            // Optimistic lock
            $table->unsignedBigInteger('version')->default(0);

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['contact_id', 'status']);
            $table->index(['flow_id', 'status']);
            $table->index(['flow_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_sessions');
    }
};
```

---

## Что НЕ делать

- ❌ **Не писать `module.*` в `flow_sessions.state` JSON** — это split authority и stale data. `module.*` резолвится lazy через accessor.
- ❌ **Не делать `FlowState` зависимым от `NamespaceResolverRegistry`** — тогда он становится God object. `FlowState` — чистый value object без зависимостей.
- ❌ **Не разделять `NamespaceResolverInterface` на Readable/Writable** — лишний branching в registry. Readonly policy enforce-ится внутри реализации.
- ❌ **Не добавлять `rag.*` в `Writable`** — `rag.*` это `NodeRestricted`. Без этого `set_attribute → rag.answer` пройдёт валидацию.
- ❌ **Не регистрировать переменные** — регистрируются только владельцы namespace.
- ❌ **Не делать `expired` отдельным статусом** — это вычисляемое состояние через `expires_at`.
- ❌ **Не писать tenant-aware логику внутри миграции** — Migration Isolation Contract.

---

## Ожидаемый результат тестов

```
✓ StatePath::from('flow.name') → namespace=Flow, owner=null, leaf='name'
✓ StatePath::from('module.hr.department') → namespace=Module, owner='hr', leaf='department'
✓ StatePath::from('module') → throws InvalidStatePathException
✓ StatePath::from('module.hr') → throws InvalidStatePathException
✓ StatePath::from('unknown.foo') → throws InvalidStatePathException

✓ FlowState::get на flow.name → 'Александр'
✓ FlowState::set nested: flow.invoice.total → array structure correct
✓ FlowState::toArray() roundtrip через fromArray()

✓ SessionStateResolver (system.*) + WriteContext::engine() → OK
✓ SessionStateResolver (system.*) + WriteContext::builder() → ReadonlyNamespaceException
✓ SessionStateResolver (flow.*) + WriteContext::builder() → OK
✓ SessionStateResolver (flow.*) + WriteContext::engine() → OK

✓ RagStateResolver + WriteContext::node('rag_query') → OK
✓ RagStateResolver + WriteContext::node('send_message') → ReadonlyNamespaceException
✓ RagStateResolver + WriteContext::builder() → ReadonlyNamespaceException

✓ ModuleStateResolver::get → delegates to accessor, not FlowState
✓ ModuleStateResolver::set + WriteContext::accessor('hr') → OK
✓ ModuleStateResolver::set + WriteContext::builder() → ReadonlyNamespaceException

✓ StateReader::get('module.hr.department') → accessor вызван, FlowState не тронут
✓ StateWriter::set('flow.name', 'Alex', WriteContext::builder()) → FlowState обновлён
✓ StateWriter::set('system.foo', 'x', WriteContext::builder()) → ReadonlyNamespaceException
```