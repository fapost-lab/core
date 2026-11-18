# Node: `call`

Calls an external or internal service. Combines Integration (HTTP) and Action (in-process).

**Type:** `call`
**Version:** 1
**Class:** `app/Domains/Flow/Handlers/CallNodeHandler.php` (category `Integration`)
**Idempotent:** the call carries an idempotency key: the engine's execution key plus `:` and the node id (new on each loop pass, the same on a queue retry of the step), and an attempt number. A repeated execution of a step whose call already succeeded is replayed from a Redis record, not sent again (see "Outbound idempotency")

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
| `success_statuses` | list | no | Statuses that count as success, for every transport that reports one. Absent: the transport's own rule (`success_when`). See "`success_statuses`" |
| `strict_mapping` | boolean | no | `true`: a mapped path missing from a successful response sends the node to `error` instead of writing null. Default `false`. See "Strict mapping" |
| `retry` | object | no | Sends a failed call again with backoff, inside the node's 18-second window. Absent: one send. See "Retry" |

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
- `error`: transport error, action exception, or an unknown transport id; a status the policy rejects; a missing mapped path under `strict_mapping`

The node always returns `Executed`; a failed call does not fail the session, it takes `error`.
Metadata carries `status_code`, `error_code`, `idempotency_key`, `attempt_number` and, for a replayed call, `deduplicated` when present.
With `retry`, `attempts` is the number of sends this execution made (when more than one), and `retry_stopped: "window"`
says a retryable failure was not sent again because the next send did not fit the window.

## `success_when` policy (HTTP transport)

`transport_options.success_when` decides which HTTP responses count as success:

| Value | Behaviour |
|-------|-----------|
| `"2xx"` (default) | 200-299 -> `success`; everything else -> `error` |
| `"any_response"` | any HTTP response, including 4xx and 5xx -> `success`; only a transport failure -> `error` |
| `"2xx_or_4xx"` | 2xx and 4xx -> `success` (for APIs where 4xx is business meaning); 5xx -> `error` |

## `success_statuses`

A literal list (not templates) of the response statuses that count as success: a number (`404`), a class (`"2xx"`) or
a range (`"400-404"`), 100 to 599, at most 20 entries. When set it **replaces** the transport's own verdict in both
directions: a listed status takes `success` even if `success_when` would have refused it, and an unlisted one takes `error`
even if it was a 2xx (`error_code` is `http_4xx`, `http_5xx` or `http_other`). The response is mapped and saved the same
way either way. A result that carries no status (a transport failure, `egress_denied`, a `handler` action) keeps the
transport's verdict, so the option is only meaningful for a transport that puts `status_code` in the result metadata
(`http` today). The builder's test call applies the same verdict.

The verdict is the node's, so it decides what the outbound ledger records: a listed status is a finished call and is
replayed, not sent again, on a repeated execution; an unlisted one is not recorded and stays retryable (see
"Outbound idempotency"). Validation at publish: `call_invalid_success_statuses` (a malformed list) and
`call_invalid_strict_mapping` (not a boolean), both errors.

### Failure boundary

| Kind | Handle |
|------|--------|
| Transport-level failure (DNS, connection refused, timeout, TLS) | **always `error`** (`error_code = transport_failure`) |
| Target refused by the egress guard (non-public address, directly, via DNS or via a redirect) | **always `error`** (`error_code = egress_denied`, metadata `host` and `reason`, never the resolved IP) |
| An HTTP response was received (any code) | per the `success_when` policy |

The actual HTTP status is always in the result metadata (`status_code`). For a response that the policy
does not accept, the error code is `http_4xx`, `http_5xx` or `http_other`, usable by a downstream branch.

For the `handler` transport: an action that returns is `success`; an action that throws becomes
`error_code = action_exception` and takes `error`. An action registered by a Solution that the tenant has
not activated is not called: `error_code = solution_inactive` (metadata `action_id`, `solution_id`), the flow
log shows `solution_inactive: <solution id>`, and the node takes `error`. A refusal still spends one call
execution.

### Egress guard (HTTP transport)

The `target` is tenant-steered, so the HTTP transport never connects to a non-public address.
`GuardedHttpClient` installs `EgressGuardMiddleware` inside Guzzle's redirect middleware, so it runs for
the first request and for every redirect hop (max 5, `http` and `https` only):

1. Scheme other than `http`/`https`: `scheme_not_allowed`.
2. Empty host, IPv6 zone id, or a numeric host that is not a canonical IPv4 address (`2130706433`,
   `0x7f.1`, `127.1`, `0177.0.0.1`): `invalid_host`.
3. An IP literal is classified directly. A name is resolved through `HostResolverInterface`
   (`DnsHostResolver`: DNS A and AAAA, falling back to the system resolver for `/etc/hosts` names); **any** non-public address refuses the whole
   request. A name that does not resolve is a `transport_failure`.
4. The connection is pinned to the checked addresses with `CURLOPT_RESOLVE`, so curl never resolves the
   name again (DNS rebinding) and SNI and certificate checks keep using the host name.

`AddressClassifier` denies loopback, private, CGNAT, link-local, multicast, unspecified and reserved
ranges for IPv4 and IPv6, and judges IPv4-mapped, IPv4-compatible, NAT64 and 6to4 addresses by the
embedded IPv4 address; PHP's `FILTER_FLAG_GLOBAL_RANGE` is a second check that must agree. Reasons:
`private`, `loopback`, `link_local`, `multicast`, `unspecified`, `reserved`, `metadata`.

Operator settings (`config/flow.php`, `flow.egress`): `FLOW_EGRESS_ALLOW` (CIDR ranges, addresses,
host names; empty by default) and `FLOW_EGRESS_PROXY`. A listed network lets its addresses through; a
listed host name skips classification but is still resolved and pinned. Metadata addresses
(`169.254.169.254`, `169.254.170.2`, `fd00:ec2::254`, `100.100.100.200`, `168.63.129.16`) are refused
whatever is allowed. There is no on/off switch. `HTTP_PROXY`/`HTTPS_PROXY` from the environment never
apply to calls (the request sets `proxy` explicitly); behind `FLOW_EGRESS_PROXY` the pin is skipped
and the proxy must refuse private ranges itself.

A refusal is logged as a warning (tenant, session, node, host, reason, resolved addresses; never the
URL path or query). The `call_executions` unit is consumed before the transport runs, so a refused
call still counts. A replayed step (see "Outbound idempotency") is neither asked nor counted again. `CallTester` (builder test call) uses the same transport and returns the same
`error_code`.

## Behavior

1. Detect legacy shape (no `transport` and no `target`) and run `executeLegacy()`.
2. Read `transport` (default `http`). If it is not registered, return `Executed` with the `error`
   handle and metadata `error_type = unknown_transport`. The session is not failed.
3. Render `target`, `parameters` and `transport_options` through `TemplateRenderer` against the live
   state.
4. Build `CallRequest{target, parameters, options}` and `CallContext{tenantId, contactId, sessionId,
   nodeId, idempotencyKey = "{engineKey}:{nodeId}"}`. The engine key changes with each persisted step, so
   every pass of a loop gets a new key, and it stays the same when the queue retries the step; the node
   id is a defensive suffix.
5. Ask the ledger for an earlier success of that key and, if there is one, use it as the result (steps 6
   and 7 run on it); otherwise count the send, pass its number to the transport as `options.attempt_number`
   (the node overwrites that key, a flow author cannot set it), call `transport->execute()` and, on a
   `success`, record the result. See "Outbound idempotency".
6. Apply the response (see below), always, success or error.
7. Return `Executed` with `success` or `error`.

## Outbound idempotency

`adr-20261118-call-outbound-idempotency-strategy`. The node, not the transport, owns the key, the attempt
number and the record (`CallExecutionLedgerInterface`, Redis implementation `RedisCallExecutionLedger`).

- **Key:** `{engineKey}:{nodeId}`, as above.
- **Attempt number:** one more for every time the key is actually sent (Redis `flow:call:{tenant}:{key}:attempts`),
  starting at 1. The HTTP transport sends it as the `X-FaPost-Attempt-Number` header (a header the flow sets itself
  wins) next to `Idempotency-Key`. The `handler` transport cannot receive it until Foundation's `CallContext` has
  a field for it. Node metadata carries `attempt_number`.
- **Record:** after a `success`, the whole result (payload, error code, metadata) is kept under
  `flow:call:{tenant}:{key}:done`. A repeated execution of the same key (the queue retried the step after the worker
  died, or the session save lost a race) finds it, does not call the transport and continues with the stored
  result, so variables and the handle are the same; its metadata has `deduplicated: true`. A failed call is not
  recorded and stays retryable; an execution that died in flight leaves only the attempt counter, so the next run
  sends again with the same key.
- **Window:** the platform setting `flow.call.dedup_ttl_hours` (default 192 hours, from `FLOW_CALL_DEDUP_TTL_HOURS`;
  minimum 168 = Horizon's failed-job retention, maximum 720). A retry from the `failed_jobs` table past the window
  re-sends with the same `Idempotency-Key`.
- **Limits:** a result over 256 KiB is not recorded; a Redis failure is logged and the call is sent anyway. The
  builder's test call never uses the ledger. Request headers and parameters are never stored.

## Retry

`adr-20261119-call-retry-inside-the-node-window`. An optional literal object (never templates):

```json
"retry": { "max_attempts": 3, "delay_ms": 500, "backoff": "exponential", "on": ["network", "5xx", "429"] }
```

| Field | Default | Bounds | Meaning |
|-------|---------|--------|---------|
| `max_attempts` | 3 | 1–5 | Sends in all, the first included; 1 is no retry |
| `delay_ms` | 500 | 100–5000 | The wait before the first retry |
| `backoff` | `exponential` | `fixed`, `exponential` | `exponential` doubles the wait on each retry, capped at 5000 ms |
| `on` | all three | non-empty subset | Which failures are retried: `network` (`transport_failure`: no connection, a timeout), `5xx`, `429` |

- **Only the node's verdict is retried.** `success_statuses` judges first, so a status the node accepts is a success
  and is never sent again. A `strict_mapping` miss follows a successful call and is not retried. Every other failure
  (another 4xx, `egress_denied`, `invalid_target`, a Solution refusal, `action_exception`) goes to `error` at once.
- **`Retry-After`** in seconds (a 429 or a 503) replaces the backoff when it asks for longer; a date is ignored.
- **Same key, next attempt.** Every send carries the same `Idempotency-Key`; the ledger counts each one, so
  `X-FaPost-Attempt-Number` rises. Only a success is recorded. The execution spends one `call_executions` unit
  however many sends it makes. A replayed execution sends nothing and so never retries.
- **The window.** The waits happen inside the step, which holds the worker and the session lock (extended only between
  nodes, TTL 30 s), and an automation segment is sized for a node of at most 18 s (`adr-20261118-segmented-automation-runs`).
  So a retry is sent only when, counted from the first send, its wait, the longest name lookup (8 s) and at least one
  second of request fit in 18 s, and its `timeout` is cut to what is left. With the default `timeout` of 10 s a call
  that timed out is usually not retried (`retry_stopped: "window"`); lower the timeout to retry slow calls. A longer
  backoff that parks the session (as `delay` does) is not built.
- **Not retried:** legacy nodes and the builder's test call (one send each).

Validation at publish: `call_invalid_retry` (an unknown field, a value out of bounds, an empty `on`), an error.

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
- A missing path writes `null`; it never fails the node, unless `strict_mapping` is on (below).
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

### Strict mapping

With `strict_mapping: true`, a `result_mapping` path that is absent from the response of a **successful** call sends the node
to `error` (`error_code = mapping_missing`, metadata `missing_paths`, flow log `mapping_missing: <paths>`) and no mapping
entry is written; `save_to_variable` still is. A path is missing only when it does not exist: a present `null`, `""` or `[]`
is a value. An error response is mapped leniently as before: it already takes `error`, and its body rarely has the success
shape. The call was sent and succeeded, so a replay of it fails the same way and does not send again.

## Built-in transports

See [04-call-transport-layer.md](../04-call-transport-layer.md), section 4.3.

`http`:
- methods GET, POST, PUT, DELETE, PATCH
- parameter keys: `body.*`, `query.*`, `headers.*`, `auth.bearer`, `auth.basic.username`, `auth.basic.password`
- the response goes to `CallResult.payload` (parsed JSON for a JSON `Content-Type`, otherwise raw text)
- the idempotency key goes into the `Idempotency-Key` header, the attempt number into `X-FaPost-Attempt-Number`

`handler`:
- `target` is an action id
- `parameters` are passed as an array to `ActionHandlerInterface::handle($parameters, $context)`
- the return value becomes `CallResult.payload`

## Validation

`ValidateFlowService` has no call-specific rules beyond the schema (no required fields are declared) and the handler's own
`validate()`: the `success_statuses` list and the `strict_mapping` flag (see above).
An unknown transport, a malformed target or an invalid `success_when` surfaces at runtime as the
`error` handle. There is a builder "test call" endpoint (`CallTestController`, `CallTester`) for
trying a request before publishing.

Tests: `tests/Unit/Domains/Flow/BuiltInNodeHandlersTest.php`,
`tests/Unit/Domains/Flow/Call/HandlerTransportTest.php`,
`tests/Feature/Domains/Flow/Call/HttpTransportTest.php`, `CallTesterTest.php`,
`CallNodeSuccessStatusesAndStrictMappingTest.php`, `tests/Unit/Domains/Flow/Call/CallSuccessStatusesTest.php`.

---

## Related

- [04-call-transport-layer.md](../04-call-transport-layer.md) - the transport layer behind the call node
