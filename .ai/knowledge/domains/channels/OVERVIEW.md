---
id: domain-channels
type: domain
status: active
summary: Channel aggregate, integration registry, Telegram module, MessageSender, typing
domains:
  - channels
topics: []
load: domain
paths:
  - "app/Domains/Channels/**"
  - "app/Domains/Messaging/**"
  - "app/Jobs/Messaging/**"
  - config/messaging.php
  - "tests/Unit/Domains/Channels/**"
  - "tests/Unit/Domains/Messaging/**"
  - "tests/Feature/Channels/**"
  - tests/Architecture/MessagingBoundariesTest.php
  - "database/migrations/tenant/*channels*"
reviewed_at: 2026-10-16
---
# Channels

## Responsibility

Channels owns the `Channel` aggregate — an assistant's transport endpoint on one messenger, with
an encrypted token, a secret token, config and an active flag — and its lifecycle. It holds the
registry of channel integrations (which sender, registrar and adapter serve each channel type),
the provider module (`Telegram/`), and outbound delivery: `MessageSender`
(idempotency, per-chat rate limit, provider sender lookup, outbound transcript), typing
indicators, and the messaging jobs.

This domain covers two folders, `app/Domains/Channels` and `app/Domains/Messaging`. Messaging
holds `MessageSender`, `Typing/` (`TypingIndicatorService`, `TypingSession`,
`TypingHeartbeatRegistry`), `Exceptions/` and `Providers/MessageSenderServiceProvider`. *(inferred:
its only foreign dependency is `ChannelRegistry::sender()`; the Telegram code used to live under
Messaging, and its tests still use the `Tests\Unit\Domains\Messaging\Telegram` namespace)*

## Boundaries

- Bindings are split across three providers: `ChannelsServiceProvider` (registry singleton,
  provider modules), `app/Providers/AssistantServiceProvider.php` (`ChannelServiceInterface`,
  `ChannelWebhookRegistryInterface`), and `MessageSenderServiceProvider` (`MessageSenderInterface`,
  scoped). Typing services are bound scoped in `FlowServiceProvider`.
- Depends on Tenancy (registry writer and reader, `TenantSwitcher`), Webhook
  (`WebhookUrlGenerator`), the `Assistant` model, and Conversation
  (logger and capture factory).
- Nearly every domain uses the concrete `Channel` model. The foundation `MessageSenderInterface`
  is used by Flow, Broadcasting and Conversation.

## Entry points

- `ChannelRegistry.php`, `ChannelIntegrationDefinition.php` (the read model). Every channel, built-in or a connector's
  (`adr-20261016-connector-package-type`), comes from `ConnectorChannels`, a process singleton filled once at boot and
  read on every lookup, so the registry may be resolved before or after boot. Both registrars hand the channel to
  `ChannelRegistration`, which holds the shared checks, stores it and tags its media classes:
  `BuiltInChannelRegistrar` (accepts only `ChannelTypeEnum` types, no owner) is used by `ChannelsServiceProvider`
  for the built-in modules (`Telegram/TelegramChannelServiceProvider`, an `AbstractConnectorServiceProvider`), in
  its booting callback, before the Solution provider registers the connectors' channels and freezes;
  `App\Infrastructure\Solution\ConnectorChannelRegistrar` adds the manifest, built-in and duplicate checks. A
  definition carries the owning `connectorId` (null for a built-in). `adapter()` returns Foundation's
  `ChannelInboundAdapterInterface`. A connector may never register a built-in type.
- A channel type is a string owned by a registered integration. `Channel::type` (and `Contact::platform`) use
  `Casts/ChannelTypeCast`: a string or a `BackedEnum` on write, always a string on read, never throws, so a row of a
  type nobody serves still hydrates. `ChannelTypeEnum` only lists the built-in types. `ChannelRegistry::types()`,
  `has()` and `label()` (a built-in's translation, a connector's manifest `name`) are what the console, validation
  (`Rule::in`) and ingress publishing read. A channel whose type `!has()` is unavailable: the console marks it, edit
  and update answer 409, delete works, and `platform:update` warns per tenant and type
  (`app/Console/Platform/TenantUnservedChannelsFinder`).
- `Contracts/ChannelRegistryInterface::profile()` returns the `Fapost\Foundation\Channel\Settings\ChannelProfileInterface`
  an integration declared (`ChannelIntegrationDefinition::$profileClass`, `ConnectorChannel::$profile`), or an
  `EmptyChannelProfile` (no settings, any secret token, no link). The console form, `ChannelFieldRules`, the
  presenter, `ChannelService::update` and `Services/ChannelPublicLinks` read it; no Core code outside
  `Telegram/` branches on a channel type. Telegram's is `Telegram/TelegramChannelProfile`; its media limits
  (`media.channel.limits`) and `config/telegram.php` (merged by its provider) live in the module too.
- `Telegram/TelegramChannelServiceProvider.php` — the reference integration; tags
  `channels.telegram_delivery`, `media.channel.uploader`, `media.channel.downloader`.
- `Services/ChannelService.php`, `Observers/ChannelObserver.php`. `ChannelService::reregisterWebhook` is the
  manual retry of a refused registration.
- `Services/ChannelWebhookStatusRecorder.php` writes `channels.webhook_status` / `webhook_status_at` straight to
  the row (no model events); `Services/ChannelWebhookSyncOutcome.php` is a scoped note of the refusals of the
  current request or job, read by the screens after a delete or deactivation.
- `Services/AssistantChannelService.php` — reads and writes the channels of an assistant the caller names (tenant
  and assistant bounded explicitly, no implicit tenancy scope); the assistant's console (`ChannelController`) and the
  admin panel (`AssistantController`, `AssistantChannelController`) use it. The
  type of a channel is chosen at creation and does not change in the console: changing it would leave the old
  provider's webhook registered against a hash the router now reads as another type.
  `Telegram/TelegramWebhookOptions.php` holds the update types and the `max_connections` bounds.
- `app/Domains/Messaging/MessageSender.php`, `app/Domains/Messaging/Typing/`.
- Jobs in `app/Jobs/Messaging/`: `BroadcastSendJob` (`messaging.broadcast`) and
  `SyncChannelWebhookJob` (`messaging.system`). `messaging.transactional` is reserved and has no
  job (the unused `SendTransactionalMessageJob` was removed in 2026-10). Flow replies are sent inline by
  `FlowMessageSender` → `MessageSender::send()` inside the `flow.execution` job.
