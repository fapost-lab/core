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
        public ?CredentialSecret $credential = null,
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
the engine's execution key plus `:` and the node id (new on each loop pass, the same on a queue retry of the step) (see [nodes/06-call.md](nodes/06-call.md)).

The attempt number is not in `CallContext` (Foundation owns it): the call node passes it in
`CallRequest::$options['attempt_number']` (`App\Domains\Flow\Call\CallAttempt::OPTION`). A transport never keeps
idempotency state of its own: the node's ledger decides whether a call is sent at all.

`CallRequest::$credential` is a saved credential the call node resolved (`Fapost\Foundation\Credentials\DTO\CredentialSecret`).
It is never part of `parameters` or `options`, it cannot be serialized (a job, a cache or a log context that tries throws), and
only the `http` transport applies it (see 4.3). A transport that does not use credentials ignores the field; the node
refuses a credential on any transport but `http` before it gets here.

A node `retry` ([nodes/06-call.md](nodes/06-call.md#retry)) calls the transport again with the same `CallContext`, the
next attempt number and `options['timeout']` cut to what is left of the node's window. A transport never retries on its
own: one `execute()` is one send.

## 4.3 Built-in transports

Registered in `FlowServiceProvider` through `CallTransportRegistry`.

**HttpTransport** (`id() = 'http'`, `app/Domains/Flow/Call/Transports/HttpTransport.php`):

- Laravel HTTP client; methods GET, POST, PUT, DELETE, PATCH. The target is `"{METHOD} {URL}"`.
- Parameters are bucketed by prefix: `body.*`, `query.*`, `headers.*`, `auth.bearer`,
  `auth.basic.username` / `auth.basic.password`. Options: `timeout` (seconds, default 10), `headers`,
  `success_when`, `body_raw` (a pre-built JSON string that wins over `body.*`).
- The `Idempotency-Key` header is set from `CallContext::$idempotencyKey`, and `X-FaPost-Attempt-Number` from `options.attempt_number`, unless the caller supplied one.
- Response parsing: JSON when the `Content-Type` is JSON, otherwise raw text.
- The success boundary is `options.success_when`: `2xx` (default), `any_response`, `2xx_or_4xx`. The node's own `success_statuses` (see `nodes/06-call.md`) overrides it, for any transport.
  Transport-level failures (DNS, timeout, TLS) are always errors with code `transport_failure`.
- Error codes: `invalid_target`, `invalid_method`, `egress_denied`, `transport_failure`, `http_4xx`,
  `http_5xx`, `http_other`, and with a credential `credential_host_denied`, `credential_unavailable`.
- The request goes through `GuardedHttpClient` (`app/Domains/Flow/Call/Egress/`), never the raw HTTP
  client; a PHPat rule (`tests/Architecture/EgressGuardTest.php`) enforces it. The guard refuses
  non-public addresses (see `nodes/06-call.md`, "Egress guard") and ends such a call with
  `egress_denied`, metadata `{host, reason}`.
- With `CallRequest::$credential` (a saved credential of the tenant, see `nodes/06-call.md`, "Saved credentials"):
  1. the target host must match the credential's allowed hosts (`HostMatcher`: an exact host or `*.domain`), else
     `credential_host_denied` with metadata `{host}` and no connection (a warning is logged with the tenant, node, host
     and credential id, never the path or query);
  2. the credential's type (`CredentialTypeRegistry`) supplies the headers and query; they replace the author's of the same
     name (headers compared case-insensitively), and the author's `auth.bearer` / `auth.basic.*` are ignored. A type that
     cannot authorize (an unknown type, a missing setting, an OAuth2 endpoint that gave no token) is `credential_unavailable`;
  3. redirects are not followed (`withoutRedirecting()`): a 3xx is the answer, so the credential never goes to a host
     the admin did not list;
  4. every returned result, the error ones and the exception messages included, has the secret strings the authorization
     lists (the values, `base64(user:pass)`, an OAuth2 access token) replaced by `***` in the payload and the whole
     metadata (`SecretRedactor`). The error code is left alone. The ledger records what the transport returned, so a
     stored result is masked too;
  5. a 401 from the target drops the cached OAuth2 token of the credential (no resend; retries are the node's business);
  6. authorizing spends the call's own `timeout`: an OAuth2 token request on a cold cache leaves the target only what
     is left of it, and with under a second left nothing is sent (`credential_unavailable`, metadata `{reason: timeout}`),
     so a credential never stretches the node past the window the runtime sizes its jobs and locks by. The credential's
     query reaches the URL on every method, a DELETE without a body included.
  The transport is a singleton without tenant state: the credential travels in the request, the type registry is a
  singleton of classes, and the scoped `OAuth2TokenCache` is taken from the container only when a 401 needs it.

**HandlerTransport** (`id() = 'handler'`, `app/Domains/Flow/Call/Transports/HandlerTransport.php`):

- Dispatches to `ActionHandlerRegistry`; the target is the action id. The registry keeps handler
  classes and an owner (a Solution id, or null for Core) and builds the handler on every call in the
  current scope.
- An action owned by a Solution the current tenant has not activated returns
  `CallResult::error('solution_inactive')` (metadata `{action_id, solution_id}`) before the handler is
  built; the check is `SolutionAvailabilityInterface`, asked on every call.
- `ActionHandlerInterface::handle($parameters, $context)` returns the payload, which becomes
  `CallResult::$payload`. Any exception from the action becomes `CallResult::error('action_exception')`;
  an unknown id becomes `action_not_found`; a Solution's action for a tenant without that Solution is
  `solution_inactive`. The call node therefore never sees an exception from a
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
solution actions register through `CoreRegistrar` (`registerActionHandler`, by class; a Solution may
register only ids its manifest declares, Core's own ids start with `core.` or `platform.`) before the
registry is frozen.

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
