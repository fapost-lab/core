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

- Owns `ChannelAdapterInterface` and `WebhookRegistryResolverInterface`.
- Depends on Channels (`ChannelRegistryInterface`, `ChannelTypeEnum`, the `Channel` model),
  Contact (`ContactServiceInterface`), Tenancy (`WebhookRegistryReaderInterface`,
  `TenantSwitcher`, `RuntimeTenant`), Flow (`MessageRouter`), Conversation (logger and capture
  factory) and Assistant.
- Channels depends back on it for `ChannelAdapterInterface` and `WebhookUrlGenerator`, which
  makes an import cycle. *(inferred: the adapter port sits in the consumer while the registry
  returning it sits in Channels)*
- The gateway shares no code with PHP. It is coupled by wire formats only: the Redis keys
  `webhook:<hash>`, `ingress:spec:<platform>` and `processed:<key>`, the registry entry JSON,
  the `InboundWebhookPayload` v1 format, and the Laravel queue job
  `RawIncomingMessageHandler@handle`. `contracts/ingress/golden.json` is executed by both.

## Entry points

- `POST /webhook/{channel}/{hash}` → `Http/WebhookController.php` → `Jobs/IncomingMessageJob.php`.
- Gateway-produced jobs: `Jobs/RawIncomingMessageHandler.php`.
- Gateway: `gateway/cmd/gateway/main.go`, `gateway/internal/ingress/handler.go`.
- Runtime choice: `IngressDriver::fromConfig`, `Services/WebhookUrlGenerator::usesGateway`.
- Console: `ops:ingress-migrate`, the ingress spec publisher, the gateway doctor and installer
  (`app/Console/Commands/Ops`, `app/Console/Commands/Platform`).
