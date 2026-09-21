---
id: glossary-channel-ingress
type: glossary
status: active
summary: Webhook public hash, registry entry, ingress spec and driver, channel adapter, processed key
domains:
  - channel-ingress
topics: []
load: domain
paths:
  - "app/Domains/Webhook/**"
  - "gateway/**"
  - "contracts/ingress/**"
  - config/webhook.php
  - "tests/Unit/Domains/Webhook/**"
---
# Channel ingress glossary

## Webhook public hash

The random 48-character URL segment identifying one channel's webhook endpoint. The gateway
treats it as a credential. It is rotated only through Channels' `ChannelService`.

## Webhook registry entry

The mapping from a webhook public hash to tenant, channel and platform: a landlord table row,
cached in Redis as `webhook:<hash>`.

## Ingress spec

A declarative verification scheme plus idempotency-key template for one platform, published to
Redis as `ingress:spec:<platform>` and executed by both ingress runtimes.

## Ingress driver

Which runtime new channels register their webhook against: `laravel` or `gateway`
(`webhook.ingress.driver`). An unknown value means `laravel`.

## Ingress drift

A channel whose registered webhook host differs from the one the configured driver would use;
fixed by `ops:ingress-migrate`.

## Channel adapter

The per-platform inbound implementation of `ChannelAdapterInterface`: signature verification,
idempotency key, payload normalisation.

## Processed key

The inbound deduplication marker `processed:<key>`, set with NX and a 24-hour TTL.

## Golden fixtures

`contracts/ingress/golden.json`: shared cases both runtimes must verify identically.
