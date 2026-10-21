---
id: domain-channel-ingress
type: domain
status: active
summary: "Webhook ingress on Laravel or the Go gateway: hash lookup, signature, dedup, queue handoff"
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
  - app/Console/Commands/Ops/GatewayDoctorCommand.php
  - app/Console/Commands/Ops/MigrateIngressCommand.php
  - app/Console/Commands/Ops/PublishIngressSpecsCommand.php
  - app/Console/Commands/Ops/WebhookRegistryHealthCommand.php
reviewed_at: 2026-10-16
---
# Channel ingress

## Responsibility

Channel ingress gets messenger webhooks into the system without touching tenant data on the
request path. It looks up the webhook public hash in the registry, verifies the signature,
deduplicates, and queues the delivery on `flow.execution`. The queued job switches tenant,
normalises the payload through the channel adapter, resolves the contact and channel, logs the
inbound message to the transcript, and hands off to Flow's `MessageRouter`.

There are two interchangeable ingress runtimes: the Laravel route, and the optional Go gateway
in `gateway/`. Code lives in `app/Domains/Webhook` — the folder name predates this domain name.

## Boundaries

- Owns `WebhookRegistryResolverInterface`. The inbound adapter contract is Foundation's `ChannelInboundAdapterInterface`.
- Depends on Channels (`ChannelRegistryInterface`, `ChannelTypeEnum`, the `Channel` model),
  Contact (`ContactServiceInterface`), Tenancy (`WebhookRegistryReaderInterface`,
  `TenantSwitcher`, `RuntimeTenant`), Flow (`MessageRouter`), Conversation (logger and capture
  factory) and Assistant.
- Channels depends back on it for `WebhookUrlGenerator`, which makes an import cycle.
- The gateway shares no code with PHP. It is coupled by wire formats only: the Redis keys
  `webhook:<hash>`, `ingress:spec:<platform>` and `processed:<key>`, the registry entry JSON,
  the `InboundWebhookPayload` v1 format, and the Laravel queue job
  `RawIncomingMessageHandler@handle`. `contracts/ingress/golden.json` is executed by both.

## Request path details

- The controller answers `{"ok": true}` in every case, including a bad signature, an unknown hash
  or a duplicate (the first two are reported, not returned).
- Dedup: `WebhookController::markProcessed` sets `processed:{idempotency_key}` (86400 s, `NX`)
  before the job is dispatched, so a duplicate update never reaches the queue.
- Verification and the idempotency key go through the adapter's declarative `ingressSpec()`
  (`ProvidesIngressSpecInterface`, executed by `IngressSpecExecutor`), so the controller and the
  gateway run the same rules. Every adapter has one: built-in and connector adapters alike implement
  `ChannelInboundAdapterInterface`, and no adapter verifies in its own code.
- Hash lookup in `Services/WebhookRegistryResolver`: Redis `webhook:{hash}` first; on a miss it reads
  the landlord registry through `WebhookRegistryReaderInterface` and writes the entry back to Redis.
  A short `warming:{hash}` leader lock (5 s) is taken on a miss, but both leader and followers do
  their own landlord read at once — nothing sleeps in the hot path. The database is the source of
  truth, Redis a cache.

## Entry points

- `POST /webhook/{channel}/{hash}` → `Http/WebhookController.php` → `Jobs/IncomingMessageJob.php`. A connector's
  inbound adapter (`Fapost\Foundation\Channel\ChannelInboundAdapterInterface`) has no verification code: the
  controller always verifies it and derives its idempotency key through its `IngressSpec`. The platform is a plain
  string along the whole path (`WebhookRegistryEntry`, `ChannelAdapterResolver`, `IncomingMessageJob`). Specs are
  published for every type in `ChannelRegistry::types()`; `IngressSpecPublisher` keeps the published types in the
  Redis set `ingress:specs` and deletes `ingress:spec:{type}` for a type no longer registered. A webhook of a type
  with no adapter is logged and answered 200; the job logs and returns without retrying.
- Gateway-produced jobs: `Jobs/RawIncomingMessageHandler.php`.
- Gateway: `gateway/cmd/gateway/main.go`, `gateway/internal/ingress/handler.go`.
- Runtime choice: `IngressDriver::fromConfig`, `Services/WebhookUrlGenerator::usesGateway`.
- Console: `ops:ingress-migrate`, the ingress spec publisher, the gateway doctor and installer
  (`app/Console/Commands/Ops`, `app/Console/Commands/Platform`).
