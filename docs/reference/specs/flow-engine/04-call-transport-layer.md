# 04. Call Transport Layer

## 4.1 CallTransportInterface

```php
namespace Fapost\Foundation\Flow\Call;

interface CallTransportInterface
{
    public function id(): string;

    public function execute(CallRequest $request, CallContext $context): CallResult;
}
```

`id()` is an instance method, and the transport has no `version()`. A transport reports failure by
returning `CallResult::error(...)`; the built-in ones do not throw.

## 4.2 DTOs

All in `Fapost\Foundation\Flow\Call`, all `final readonly`.

```php
final readonly class CallRequest
{
    public function __construct(
        public string $target,
        public array $parameters = [],
        public array $options = [],
    ) {}
}

final readonly class CallContext
{
    public function __construct(
        public string $tenantId,
        public string $contactId,
        public string $sessionId,
        public string $nodeId,
        public string $idempotencyKey,
    ) {}
}

final readonly class CallResult
{
    public function __construct(
        public bool $success,
        public mixed $payload = null,
        public ?string $errorCode = null,
        public array $metadata = [],
    ) {}

    public static function ok(mixed $payload = null, array $metadata = []): self;
    public static function error(string $errorCode, mixed $payload = null, array $metadata = []): self;
}
```

`CallContext` carries identifiers, not a tenant or session object. The idempotency key is
`{sessionId}:{nodeId}` (see [nodes/06-call.md](nodes/06-call.md)).

## 4.3 Built-in transports

Registered in `FlowServiceProvider` through `CallTransportRegistry`.

**HttpTransport** (`id() = 'http'`, `app/Domains/Flow/Call/Transports/HttpTransport.php`):

- Laravel HTTP client; methods GET, POST, PUT, DELETE, PATCH. The target is `"{METHOD} {URL}"`.
- Parameters are bucketed by prefix: `body.*`, `query.*`, `headers.*`, `auth.bearer`,
  `auth.basic.username` / `auth.basic.password`. Options: `timeout` (seconds, default 10), `headers`,
  `success_when`, `body_raw` (a pre-built JSON string that wins over `body.*`).
- The `Idempotency-Key` header is set from `CallContext::$idempotencyKey` unless the caller supplied one.
- Response parsing: JSON when the `Content-Type` is JSON, otherwise raw text.
- The success boundary is `options.success_when`: `2xx` (default), `any_response`, `2xx_or_4xx`.
  Transport-level failures (DNS, timeout, TLS) are always errors with code `transport_failure`.
- Error codes: `invalid_target`, `invalid_method`, `transport_failure`, `http_4xx`, `http_5xx`,
  `http_other`.

**HandlerTransport** (`id() = 'handler'`, `app/Domains/Flow/Call/Transports/HandlerTransport.php`):

- Dispatches to `ActionHandlerRegistry`; the target is the action id.
- `ActionHandlerInterface::handle($parameters, $context)` returns the payload, which becomes
  `CallResult::$payload`. Any exception from the action becomes `CallResult::error('action_exception')`;
  an unknown id becomes `action_not_found`. The call node therefore never sees an exception from a
  handler action.

## 4.4 ActionHandlerInterface

```php
namespace Fapost\Foundation\Action;

interface ActionHandlerInterface
{
    public function id(): string;        // 'crm.sync_contact'; "core.*" and "platform.*" are reserved
    public function version(): int;

    public function handle(array $parameters, CallContext $context): mixed;
}
```

`ActionHandlerRegistry` (`app/Domains/Flow/Action/`) is separate from `NodeHandlerRegistry`. Plugin and
solution actions register through `CoreRegistrar` before the registry is frozen.

## 4.5 Fail-on-conflict policy for the registries

A duplicate key is a `LogicException` at registration time. There is never a silent override.

| Registry | Conflict key |
|----------|--------------|
| `NodeHandlerRegistry` | `type@version` |
| `CallTransportRegistry` | `id()` |
| `ActionHandlerRegistry` | `id()` |
| `ModuleDataAccessorRegistry` | namespace prefix (must start with `module.`; `flow`, `system`, `rag` are reserved) |
| `RagAdapterRegistry` | adapter id |

All of them support `freeze()`, called once the application has booted (except in the `testing`
environment); registering after that throws. A boot failure is visible to the operator immediately.

---

## Related

- [nodes/06-call.md](nodes/06-call.md) - the `call` node uses this transport layer
- `docs/site/extending/` - how Solutions and plugins register handlers
