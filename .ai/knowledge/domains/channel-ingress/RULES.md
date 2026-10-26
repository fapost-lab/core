---
id: rule-channel-ingress
type: rule
status: active
summary: Always 200 (handshake after a verified signature excepted), constant-time verify, shared dedup key, wire version, PHP and Go parity via golden fixtures
domains:
  - channel-ingress
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Webhook/**"
  - "gateway/**"
  - "contracts/ingress/**"
  - config/webhook.php
  - "tests/Unit/Domains/Webhook/**"
reviewed_at: 2026-10-26
---
# Channel ingress rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **The HTTP controller does not touch tenant data;** the tenant is switched inside the queued
  job. Enforced: `tests/Unit/Architecture/WebhookArchitectureTest.php`.
- **Every webhook outcome answers `200 {"ok":true}`,** including a bad signature, a stale timestamp or an unknown
  hash, in both runtimes. The one exception is a spec's handshake: after a verified signature the runtime answers
  `200 text/plain` with the spec's `respond` text and neither deduplicates nor enqueues. Why *(inferred)*: a provider
  must neither retry-storm on errors nor learn which hashes exist, and the echo reflects only what the holder of the
  signing secret sent. An unsigned challenge gets the ordinary `{"ok":true}`. Enforced: `WebhookControllerTest`, the
  `handshake` cases in `golden.json`.
- **Signatures are compared in constant time** (`hash_equals`, `hmac.Equal`).
- **An empty channel secret refuses every scheme but `none`,** in both runtimes. Compared
  directly it would match an absent header or parameter, and as an HMAC key it lets anyone sign.
  An adapter without a spec is never asked to verify against one (`WebhookController`). Enforced:
  the `empty secret` cases in `contracts/ingress/golden.json`, `WebhookControllerTest`.
- **Deduplication uses the same `processed:<key>` shape and 24-hour TTL in both runtimes;** the
  gateway releases the key if the queue push fails.
- **PHP and Go verification behave identically.** Enforced: `IngressSpecGoldenTest`,
  `gateway/internal/spec/golden_test.go`, `TelegramIngressSpecParityTest`.
- **A request is verified before anything is read from it, and what is queued is the request document.** The order is
  the same in both runtimes: hash lookup, spec, verify (signature and freshness window), handshake, idempotency key,
  dedup, enqueue. `rawPayload` is `IngressSpecExecutor::document()` (the JSON body, or the JSON a spec's form field
  holds), never `Request::json()`. Enforced: `WebhookControllerTest`, the `document` cases in `golden.json`.
- **Inbound deliveries go to `flow.execution`.** Source: `conventions/queues.md`.

## Rules

- **A new spec capability raises the wire version, and a runtime that does not know the version proxies the
  request to the application.** `IngressSpec::wireVersion()` is 2 when any v2 field is set (`signed`, `timestamp`,
  `form`, `handshake`, or `|` inside an idempotency placeholder); everything else stays byte-identical v1. A Go
  gateway silently ignores JSON fields it does not know, so only a version bump makes an old gateway refuse the spec
  (`spec.Parse`) and fall back to PHP instead of accepting a replay, skipping a handshake or deduplicating a form
  body under an empty key. Raise the version in Foundation, `spec.Parse` and `golden.json` together. Enforced:
  Foundation's spec tests, `gateway/internal/spec`, `IngressSpecGoldenTest`.
- **An adapter's `normalize()` throws `InboundEventIgnoredException` for a delivery that is no user message** (the
  bot's own message, an edit, a system event). `IncomingMessageJob` logs it at debug level and ends: no contact, no
  transcript, no retry. `RawIncomingMessageHandler` runs the same job, so gateway-produced jobs behave alike.
  Enforced: `OpenChannelTypesTest`.
- **Every delivery an adapter accepts resolves its idempotency key to a non-empty id.** A key whose placeholders all
  resolve empty (`slack:{channel}:`) is one shared `processed:` slot for 24 hours in both runtimes, so every later
  delivery of that shape is dropped as a duplicate. Give the template an alternative (`{body.event_id|body.trigger_id}`)
  or make `normalize()` ignore that kind of delivery. Review only; a runtime guard is an open follow-up.
- **Change verification, the dedup key, the registry payload or the job payload in both
  runtimes and in `golden.json` in the same change.** Review only; the golden tests catch
  verification drift but not payload drift. *(proposed)*
- **Values the gateway mirrors from PHP are copied by hand** — the job `Class@method`,
  `IncomingMessageJob::$tries` (Go `maxTries`), Redis key prefixes. When changing the PHP side,
  search `gateway/` for the value. Review only. *(proposed)*
- **Do not branch on the `{channel}` path segment.** Both runtimes ignore it and take the
  platform from the registry entry.
