# 04 · Call Transport Layer

## 4.1 CallTransportInterface

```php
namespace FAPost\Foundation\Contracts\Flow\Call;

interface CallTransportInterface
{
    public static function id(): string;
    public static function version(): int;

    /**
     * @throws CallTransportException
     */
    public function execute(CallRequest $request, CallContext $context): CallResult;
}
```

## 4.2 DTO

```php
final class CallRequest
{
    public function __construct(
        public readonly string $target,
        public readonly array $parameters,
        public readonly array $options,
    ) {}
}

final class CallContext
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly FlowSessionInterface $session,
        public readonly string $idempotencyKey,
    ) {}
}

final class CallResult
{
    public function __construct(
        public readonly bool $success,
        public readonly mixed $payload,
        public readonly ?string $errorCode,
        public readonly array $metadata,
    ) {}
}
```

## 4.3 Built-in транспорты

**HttpTransport** (id='http', version=1):
- HTTP client (Guzzle / Laravel Http)
- Поддерживает GET/POST/PUT/DELETE/PATCH
- Auth: bearer, basic
- Idempotency-Key header автоматически из CallContext
- Response parsing: JSON if `Content-Type: application/json`, иначе raw text
- Success/failure boundary конфигурируется через `transport_options.success_when` (см. [nodes/06-call.md](nodes/06-call.md))

**HandlerTransport** (id='handler', version=1):
- Dispatch в ActionHandlerRegistry
- target = action ID
- ActionHandlerInterface::handle($parameters, $context) → возврат → CallResult.payload

## 4.4 ActionHandlerInterface

```php
namespace FAPost\Foundation\Contracts\Action;

interface ActionHandlerInterface
{
    public static function id(): string;          // 'crm.sync_contact', namespace 'core.*' reserved
    public static function version(): int;

    /**
     * @throws ActionExecutionException
     */
    public function handle(array $parameters, CallContext $context): mixed;
}
```

ActionHandlerRegistry — отдельный от NodeHandlerRegistry.

> **Patch v1.1:** ID collision при регистрации = `LogicException` at registration time. Никакого silent override. Resolves Open question 12.3 из v1.0.

## 4.5 Fail-on-conflict policy для всех registries

Единая политика для четырёх registries:

| Registry | Ключ конфликта |
|----------|----------------|
| `NodeHandlerRegistry` | `(type, version)` |
| `CallTransportRegistry` | `id` |
| `ActionHandlerRegistry` | `id` |
| `DataAccessorRegistry` | `namespace` |

```php
final class ActionHandlerRegistry
{
    /** @var array<string, ActionHandlerInterface> */
    private array $handlers = [];

    public function register(ActionHandlerInterface $handler): void
    {
        $id = $handler::id();
        if (isset($this->handlers[$id])) {
            throw new LogicException(
                "Action handler '{$id}' already registered. " .
                "Existing: " . get_class($this->handlers[$id]) . ", " .
                "Conflicting: " . get_class($handler) . ". " .
                "Conflicts must be resolved (rename one or remove)."
            );
        }
        $this->handlers[$id] = $handler;
    }
}
```

Boot fails → operator видит ошибку немедленно → исправляет до production. Module Registration Contract (Phase 4 plan, task 21) ловит эти ошибки на boot validation модуля и помечает модуль degraded.

---

## Связано с

- [[06-call]] — нода call использует transport layer
- [[12-solutions-modules]] — Solutions регистрируют handlers
- [[03-node-handler-interface]] — интерфейс handlers
