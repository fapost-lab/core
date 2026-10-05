# Node: `call`

Calls an external or internal service. Combines Integration (HTTP) and Action (in-process).

**Type:** `call`
**Version:** 1
**Class:** `app/Domains/Flow/Handlers/CallNodeHandler.php` (category `Integration`)
**Idempotent:** the call carries a stable idempotency key `{sessionId}:{nodeId}`

## Config

```json
{
  "transport": "http",
  "target": "POST https://api.example.com/users",
  "parameters": {
    "body.first_name": "{{contact.first_name}}",
    "body.email":      "{{contact.email}}",
    "auth.bearer":     "{{flow.api_token}}"
  },
  "transport_options": {
    "timeout": 30,
    "headers": { "X-Source": "fapost" },
    "success_when": "2xx"
  },
  "save_to_variable": { "name": "last_response", "storage": "session" },
  "result_mapping": [
    { "from": "body.data.id", "to": { "name": "created_user_id", "storage": "session" } },
    { "from": "status",       "to": { "name": "last_call_status", "storage": "session" } }
  ]
}
```

**Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `transport` | string | no | Transport id in `CallTransportRegistry`. Built-in: `http` (default), `handler`. A literal, not an expression |
| `target` | template string | yes | Meaning depends on the transport; rendered against the live state |
| `parameters` | object | no | Map `<key> -> template`; every value is rendered recursively |
| `transport_options` | object | no | Transport options, rendered, then passed through (`timeout`, `headers`, `success_when`, `body_raw`) |
| `save_to_variable` | Variable | no | Writes the whole response object `{status, body, headers}` to one variable |
| `result_mapping` | list of `{from, to}` | no | Each entry copies one response path into a `Variable` (`to`) |

A node with neither `transport` nor `target` is a **legacy** node and runs through `executeLegacy()`:
it POSTs `url` through the `http` transport with `{session_id, contact_id, state}` from
`include_state`, and saves the body to `save_to_variable` or the legacy `save_response_to` path.

## Target by transport

| Transport | target |
|-----------|--------|
| `http` | `<METHOD> <URL>`, for example `POST https://api.example.com/users` |
| `handler` | an action id in `ActionHandlerRegistry`, for example `crm.sync_contact` |

## Output handles

- `success`: the call succeeded (by the `success_when` policy for HTTP)
- `error`: transport error, action exception, or an unknown transport id

The node always returns `Executed`; a failed call does not fail the session, it takes `error`.
Metadata carries `status_code`, `error_code` and `idempotency_key` when present.

## `success_when` policy (HTTP transport)

`transport_options.success_when` decides which HTTP responses count as success:

| Value | Behaviour |
|-------|-----------|
| `"2xx"` (default) | 200-299 -> `success`; everything else -> `error` |
| `"any_response"` | any HTTP response, including 4xx and 5xx -> `success`; only a transport failure -> `error` |
| `"2xx_or_4xx"` | 2xx and 4xx -> `success` (for APIs where 4xx is business meaning); 5xx -> `error` |

### Failure boundary

| Kind | Handle |
|------|--------|
| Transport-level failure (DNS, connection refused, timeout, TLS) | **always `error`** (`error_code = transport_failure`) |
| An HTTP response was received (any code) | per the `success_when` policy |

The actual HTTP status is always in the result metadata (`status_code`). For a response that the policy
does not accept, the error code is `http_4xx`, `http_5xx` or `http_other`, usable by a downstream branch.

For the `handler` transport: an action that returns is `success`; an action that throws becomes
`error_code = action_exception` and takes `error`.

## Behavior

1. Detect legacy shape (no `transport` and no `target`) and run `executeLegacy()`.
2. Read `transport` (default `http`). If it is not registered, return `Executed` with the `error`
   handle and metadata `error_type = unknown_transport`. The session is not failed.
3. Render `target`, `parameters` and `transport_options` through `TemplateRenderer` against the live
   state.
4. Build `CallRequest{target, parameters, options}` and `CallContext{tenantId, contactId, sessionId,
   nodeId, idempotencyKey = "{sessionId}:{nodeId}"}`. The key is static per node: there is no attempt
   counter, because the node is never retried by the engine. Idempotency of the session run comes from
   the session lock; remote de-duplication is up to the receiver through the `Idempotency-Key` header.
5. Call `transport->execute()`; it returns a `CallResult`.
6. Apply the response (see below), always, success or error.
7. Return `Executed` with `success` or `error`.

**There is no retry.** The `transport_options.retry` block seen in older drafts is not implemented; a
failed call goes to `error` and the flow decides what to do.

## Response handling

Both layers are optional and apply on success **and** on error (the error body is often what the
author wants to branch on).

A response bag is built for `result_mapping.from` paths:

```
{ status, headers, body, payload, metadata }
```

`status` is `metadata.status_code`, `headers` and `body` are the response headers and parsed body;
`payload` and `metadata` are the raw `CallResult` fields (`body` and `payload` are the same value).
`from` is read with `data_get`, so `body.data.id` works.

- `save_to_variable` writes `{status, body, headers}` as one value.
- Each `result_mapping` entry writes `data_get($bag, from)` into the variable `to`.
- A missing path writes `null`; it never fails the node.
- A `session` variable lands in `stateChanges` (`flow.<name>`). A `contact` variable is written
  immediately through `ContactWriterInterface`; if the writer is unavailable the node throws
  `InvalidNodeConfigException`.

Downstream pattern:

```
call -> success
  branch:
    flow.created_user_id is_null -> handle "user_not_created"
    default -> continue
```

A strict mode that fails on a missing path is not built.

## Built-in transports

See [04-call-transport-layer.md](../04-call-transport-layer.md), section 4.3.

`http`:
- methods GET, POST, PUT, DELETE, PATCH
- parameter keys: `body.*`, `query.*`, `headers.*`, `auth.bearer`, `auth.basic.username`, `auth.basic.password`
- the response goes to `CallResult.payload` (parsed JSON for a JSON `Content-Type`, otherwise raw text)
- the idempotency key goes into the `Idempotency-Key` header

`handler`:
- `target` is an action id
- `parameters` are passed as an array to `ActionHandlerInterface::handle($parameters, $context)`
- the return value becomes `CallResult.payload`

## Validation

`ValidateFlowService` has no call-specific rules beyond the schema (no required fields are declared).
An unknown transport, a malformed target or an invalid `success_when` surfaces at runtime as the
`error` handle. There is a builder "test call" endpoint (`CallTestController`, `CallTester`) for
trying a request before publishing.

Tests: `tests/Unit/Domains/Flow/BuiltInNodeHandlersTest.php`,
`tests/Unit/Domains/Flow/Call/HandlerTransportTest.php`,
`tests/Feature/Domains/Flow/Call/HttpTransportTest.php`, `CallTesterTest.php`.

---

## Related

- [04-call-transport-layer.md](../04-call-transport-layer.md) - the transport layer behind the call node
