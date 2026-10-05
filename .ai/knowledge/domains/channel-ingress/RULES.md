---
id: rule-channel-ingress
type: rule
status: active
summary: Always 200, constant-time verify, shared dedup key, PHP and Go parity via golden fixtures
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
reviewed_at: 2026-10-05
---
# Channel ingress rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **The HTTP controller does not touch tenant data;** the tenant is switched inside the queued
  job. Enforced: `tests/Unit/Architecture/WebhookArchitectureTest.php`.
- **Every webhook outcome answers `200 {"ok":true}`,** including a bad signature or an unknown
  hash, in both runtimes. Why *(inferred)*: a provider must neither retry-storm on errors nor
  learn which hashes exist.
- **Signatures are compared in constant time** (`hash_equals`, `hmac.Equal`).
- **Deduplication uses the same `processed:<key>` shape and 24-hour TTL in both runtimes;** the
  gateway releases the key if the queue push fails.
- **PHP and Go verification behave identically.** Enforced: `IngressSpecGoldenTest`,
  `gateway/internal/spec/golden_test.go`, `TelegramIngressSpecParityTest`.
- **Inbound deliveries go to `flow.execution`.** Source: `conventions/queues.md`.

## Rules

- **Change verification, the dedup key, the registry payload or the job payload in both
  runtimes and in `golden.json` in the same change.** Review only; the golden tests catch
  verification drift but not payload drift. *(proposed)*
- **Values the gateway mirrors from PHP are copied by hand** — the job `Class@method`,
  `IncomingMessageJob::$tries` (Go `maxTries`), Redis key prefixes. When changing the PHP side,
  search `gateway/` for the value. Review only. *(proposed)*
- **Do not branch on the `{channel}` path segment.** Both runtimes ignore it and take the
  platform from the registry entry.
