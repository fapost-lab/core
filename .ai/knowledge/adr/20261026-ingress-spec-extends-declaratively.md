---
id: adr-20261026-ingress-spec-extends-declaratively
type: adr
status: accepted
date: 2026-10-26
domains:
  - channel-ingress
  - channels
paths:
  - "contracts/ingress/**"
  - "gateway/internal/spec/**"
  - "app/Domains/Webhook/Http/**"
---
# A provider the ingress spec cannot express extends the spec, and a new capability raises its wire version

## Context

`adr-20261016-connector-package-type` says a connector's inbound adapter declares verification and the idempotency
key only through `IngressSpec`, and that a provider whose signature the spec cannot express "extends
`SignatureScheme`". Slack, the contract's first outside connector (`slack-connector-package`), needed more than a new
scheme: an HMAC over a composed string (`v0:{timestamp}:{body}`), a freshness window, a handshake answered with a
value from the body (`url_verification`), and a form-encoded body whose JSON sits in one field (interactivity). Its
button presses carry no `event_id`, so one idempotency template needed a fallback. The spec is executed by two
runtimes, PHP (Foundation's `IngressSpecExecutor`) and the Go gateway, and Go's JSON decoding silently ignores fields
it does not know.

## Decision

- A provider the spec cannot express extends the declarative spec itself, in Foundation, PHP and Go together, with
  shared golden fixtures (`contracts/ingress/golden.json`). Foundation 0.33 added `signedPayload` (a template with
  `{raw}` and `{header.*}`), `IngressTimestamp`, `formField`, `IngressHandshake` and `|` alternatives in
  placeholders. `SignatureScheme` stays the list of comparison algorithms. A connector still ships no
  verification code. This refines, and does not reverse, the 2026-10-16 decision.
- A spec that uses any new capability is serialised with a higher wire version (`v: 2`); every other spec stays
  byte-identical at `v: 1`. A runtime refuses a version it does not know, and the gateway then proxies the request to
  the application, which verifies it.
- The request document is derived from the bytes alone, never from `Content-Type`: the JSON body, else the JSON in
  the spec's form field. It feeds the placeholders and is the queued `rawPayload`.
- A handshake is answered only after the signature verified, before deduplication, and is the one exception to
  "every outcome answers `200 {"ok":true}`".
- A delivery that is not a user message is dropped by the worker (`InboundEventIgnoredException` from
  `normalize()`), not by a filter language in the spec.

## Alternatives

- A provider-specific scheme (`slack_v0`): a closed list that needs a gateway release per provider.
- No spec for Slack, verified by PHP code: the adapter contract requires a spec, and the gateway would proxy all of
  Slack's traffic.
- New fields without a version bump: an older gateway would ignore the window (accept replays), skip the handshake
  and deduplicate form bodies under an empty key.
- A filter in the spec for the bot's own messages: a second condition language in both runtimes to save one queued
  job per message.

## Consequences

- Every new spec capability is a coordinated change of Foundation, the gateway and `golden.json`; the golden cases
  carry `now`, `document` and `handshake` expectations.
- An installation with an older gateway keeps working for v2 channels, through the application.
- Parity edges (JSON decoding of invalid UTF-8, surrogates, nesting depth; form escapes) are pinned case by case in
  `golden.json`; a gap there is a parity bug.
