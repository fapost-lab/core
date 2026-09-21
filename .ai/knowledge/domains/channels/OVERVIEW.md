---
id: domain-channels
type: domain
status: active
summary: Channel aggregate, integration registry, Telegram and WhatsApp modules, MessageSender, typing
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
  - "app/Filament/Assistant/Resources/Channels/**"
---
# Channels

## Responsibility

Channels owns the `Channel` aggregate — an assistant's transport endpoint on one messenger, with
an encrypted token, a secret token, config and an active flag — and its lifecycle. It holds the
registry of channel integrations (which sender, registrar and adapter serve each channel type),
the provider modules (`Telegram/`, `WhatsApp/`), and outbound delivery: `MessageSender`
(idempotency, per-chat rate limit, provider sender lookup, outbound transcript), typing
indicators, and the messaging jobs.

This domain covers two folders, `app/Domains/Channels` and `app/Domains/Messaging`. *(inferred:
Messaging is three classes whose only foreign dependency is `ChannelRegistry::sender()`; the
Telegram code used to live under Messaging, and its tests still use the
`Tests\Unit\Domains\Messaging\Telegram` namespace)*

## Boundaries

- Bindings are split across three providers: `ChannelsServiceProvider` (registry singleton,
  provider modules), `app/Providers/AssistantServiceProvider.php` (`ChannelServiceInterface`,
  `ChannelWebhookRegistryInterface`), and `MessageSenderServiceProvider` (`MessageSenderInterface`,
  scoped). Typing services are bound scoped in `FlowServiceProvider`.
- Depends on Tenancy (registry writer and reader, `TenantSwitcher`), Webhook
  (`ChannelAdapterInterface`, `WebhookUrlGenerator`), the `Assistant` model, and Conversation
  (logger and capture factory).
- Nearly every domain uses the concrete `Channel` model. The foundation `MessageSenderInterface`
  is used by Flow, Broadcasting and Conversation.

## Entry points

- `ChannelRegistry.php`, `ChannelIntegrationDefinition.php` (tagged `channels.integration`).
- `Telegram/TelegramChannelServiceProvider.php` — the reference integration; tags
  `channels.telegram_delivery`, `media.channel.uploader`, `media.channel.downloader`.
- `Services/ChannelService.php`, `Observers/ChannelObserver.php`.
- `app/Domains/Messaging/MessageSender.php`, `app/Domains/Messaging/Typing/`.
- Jobs in `app/Jobs/Messaging/`: `SendTransactionalMessageJob` (`messaging.transactional`),
  `BroadcastSendJob` (`messaging.broadcast`), `SyncChannelWebhookJob` (`messaging.system`).
