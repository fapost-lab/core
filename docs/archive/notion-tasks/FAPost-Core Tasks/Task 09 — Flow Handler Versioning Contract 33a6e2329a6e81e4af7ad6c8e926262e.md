# Task 09 — Flow Handler Versioning Contract

> Архив Notion. Актуальная документация: [[07-versioning]]


Depends on: 04
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 4
Status: Готово
Task №: 9

## Цель

Обеспечить детерминированное выполнение flow независимо от эволюции handler-кода.

Любая опубликованная flow definition исполняется строго тем handler-контрактом, который был зафиксирован в snapshot на момент публикации. Изменение handler-кода не ломает активные, waiting, delayed и paused сессии.

---

## Базовый принцип

Каждая node внутри flow snapshot хранит явную версию:

```json
{ "id": "uuid", "type": "condition", "version": 2, "config": {} }
```

Engine резолвит handler строго по паре `(type, version)` — без lookup в БД. Source of truth = код.

---

## Контракты и интерфейсы

### NodeHandlerInterface

```php
interface NodeHandlerInterface
{
    public function type(): string;
    public function version(): int;
    /** @return array<int> */
    public function supportedVersions(): array;
    public function handle(NodeExecutionContext $context): NodeExecutionResult;
}
```

### AbstractVersionedHandler

```php
abstract class AbstractVersionedHandler implements NodeHandlerInterface
{
    public function supportedVersions(): array
    {
        return [$this->version()];
    }
}
```

Если handler временно поддерживает несколько версий — `supportedVersions()` override.

### NodeExecutionContext (readonly DTO)

```php
final readonly class NodeExecutionContext
{
    public function __construct(
        public FlowSessionInterface $session,
        public array $nodeConfig,
        public int $nodeVersion,
        public string $nodeId,
    ) {}
}
```

### NodeExecutionResult (readonly DTO)

```php
final readonly class NodeExecutionResult
{
    public function __construct(
        public NodeExecutionStatus $status,
        public ?string $transition = null,
        public array $stateChanges = [],
        public array $metadata = [],
    ) {}
}
```

`transition` — semantic output ноды (`default`, `yes`, `no`, `timeout`, `error`, `case:vip`). Handler не знает topology графа и не возвращает `nextNodeId`. Engine сам резолвит target node через edges snapshot.

### NodeExecutionStatus

```php
enum NodeExecutionStatus: string
{
    case Completed = 'completed';
    case WaitingForInput = 'waiting';
    case Delayed = 'delayed';
    case Failed = 'failed';
}
```

---

## NodeHandlerRegistry

Работает только in-memory. БД не участвует в runtime resolution.

```php
final class NodeHandlerRegistry
{
    private array $handlers = [];
    private bool $frozen = false;

    public function register(NodeHandlerInterface $handler): void
    {
        if ($this->frozen) {
            throw new LogicException('Cannot register handlers after boot.');
        }
        if (! in_array($handler->version(), $handler->supportedVersions(), true)) {
            throw new LogicException(
                "Handler {$handler->type()}@{$handler->version()} must include own version in supportedVersions()."
            );
        }
        $key = $this->key($handler->type(), $handler->version());
        if (isset($this->handlers[$key])) {
            throw new LogicException("Duplicate handler registration: {$key}");
        }
        $this->handlers[$key] = $handler;
    }

    public function resolve(string $type, int $version): NodeHandlerInterface
    {
        $key = $this->key($type, $version);
        if (! isset($this->handlers[$key])) {
            throw new LogicException("Handler not found: {$key}");
        }
        return $this->handlers[$key];
    }

    public function freeze(): void { $this->frozen = true; }

    private function key(string $type, int $version): string
    {
        return "{$type}@{$version}";
    }
}
```

После регистрации всех handlers обязательно вызвать `$registry->freeze()`. После freeze runtime register запрещён.

---

## Схема БД

### flow_definitions

```php
Schema::create('flow_definitions', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('flow_id');
    $table->unsignedInteger('version');
    $table->string('name');
    $table->jsonb('nodes');
    $table->jsonb('edges');
    $table->boolean('is_active')->default(false);
    $table->timestamps();

    $table->unique(['flow_id', 'version']);
    $table->index(['tenant_id', 'flow_id']);
});
```

Partial unique index — одна активная версия на flow_id:

```sql
CREATE UNIQUE INDEX flow_definitions_active_unique
ON flow_definitions (tenant_id, flow_id)
WHERE is_active = true;
```

### Node snapshot contract (минимальная структура)

```json
{
  "id": "uuid",
  "type": "condition",
  "version": 2,
  "config": {},
  "meta": { "position": { "x": 100, "y": 200 } }
}
```

`meta` содержит editor metadata. Engine игнорирует `meta` полностью.

### Edge snapshot contract

```json
[
  {
    "id": "uuid",
    "source_node_id": "uuid",
    "target_node_id": "uuid",
    "transition": "default"
  }
]
```

Edges хранятся отдельно от nodes — упрощает валидацию графа, построение Vue Flow editor, topology checks, сохраняет handler graph-agnostic.

---

## FlowDefinitionValidator

Валидация при publish, не в runtime.

```php
final readonly class FlowDefinitionValidator
{
    public function __construct(
        private NodeHandlerRegistry $registry,
    ) {}

    public function validate(array $nodes, array $edges): array
    {
        // handler existence
        // graph connectivity
        // entry point
        // orphan nodes
    }
}
```

Обязательные проверки:

- каждая node содержит `type` и `version`
- каждая edge содержит `source_node_id`, `target_node_id` и `transition`
- handler существует в registry
- граф связен, есть entry point, нет orphan nodes
- для каждого required transition существует edge
- нет duplicate edges по паре `(source_node_id, transition)`

---

## flow_active_node_stats

Не таблица с runtime increment/decrement — это contention point и риск ложного удаления handler.

Реализация: read-only materialized view либо scheduled recalculation на основе `flow_sessions + flow_definitions`. Используется только как observability tool, не как автоматический delete gate.

---

## Version Policy

- **Backward-compatible change** (новое поле с default) — version не меняется
- **Breaking change** — version++ в новых flow, старый handler остаётся зарегистрированным
- Именование класса обязано содержать версию: `ConditionNodeHandlerV2`, `InputNodeHandlerV1`
- Session всегда исполняется по snapshot — runtime migration запрещена

---

## Safe Handler Removal Policy

Удаление handler допустимо только если в `flow_active_node_stats`:

```
active = 0
waiting = 0   ← обязательно (input node может жить неделями)
paused = 0
```

Решение принимается вручную после проверки stats. Удаление handler без проверки live sessions запрещено — даже если flow definition больше не active.

---

## HandlerVersionContract (PHPAt)

PHPat rule обязателен. Проверяет:

- каждый core handler implements `NodeHandlerInterface`
- каждый handler versioned
- namespace core handlers соблюдён

> Scope: Task 09 покрывает только core handlers. Module handler enforcement — Task 21.
> 

---

## Что НЕ делать

- `flow_node_handlers` как таблица в БД — запрещено, создаёт split authority
- `nextNodeId` в handler contract — запрещено, handler не знает topology
- Автоматическая подмена version в сессии — запрещено, immutable execution rule
- Удаление handler при `is_active = false` flow без проверки live sessions — запрещено
- Registry как mutable singleton после boot — запрещено, freeze обязателен

---

## Файловая структура

```
app/Domains/Flow/
├── Contracts/
│   ├── NodeHandlerInterface.php
│   └── NodeHandlerRegistryInterface.php
├── Registry/
│   └── NodeHandlerRegistry.php
├── Handlers/
│   └── Abstract/
│       └── AbstractVersionedHandler.php
├── DTO/
│   ├── NodeExecutionContext.php
│   └── NodeExecutionResult.php
├── Enums/
│   └── NodeExecutionStatus.php
├── Validation/
│   └── FlowDefinitionValidator.php
└── Models/
    └── FlowDefinition.php
```

---

## Ожидаемый результат

- `NodeHandlerRegistry` собирается при boot, замораживается, resolve без БД
- `FlowDefinition` хранит nodes + edges как отдельные JSONB-поля
- Partial unique index гарантирует одну активную версию на flow
- `FlowDefinitionValidator` отклоняет невалидные graphs при publish
- PHPAt rule блокирует регистрацию handler-ов без правильного interface
- `flow_active_node_stats` — read-only observability, не runtime counter