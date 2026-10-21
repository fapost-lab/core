---
id: glossary-channels
type: glossary
status: active
summary: Channel vs channel type vs platform, integration definition, provider vs message sender
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
reviewed_at: 2026-10-21
---
# Channels glossary

## Channel

An assistant's transport endpoint on one messenger. Informal synonyms: bot, integration.

## Channel type

`ChannelTypeEnum` (`telegram`): the built-in kinds of channel. Not the same enum as Contact's
`PlatformEnum` (`telegram`, `whatsapp`, `email`), although the values overlap.

## Channel integration definition

The set of class names — sender, registrar, adapter — that serve one channel type. A read model
`ChannelRegistry` builds from the `ConnectorChannel` a built-in module or a connector registered.

## Provider sender

`ProviderSenderInterface::deliver()`: the platform-specific send. Only `MessageSender` calls it.

## Message sender

`MessageSenderInterface`: the generic outbound engine every domain sends through.

## Webhook registrar

The class that registers or removes the webhook at the provider (Telegram `setWebhook`).

## Typing session

The handle of one typing indicator, refreshed by a heartbeat while a flow runs.

## Outbound idempotency key

`msg:sent:<key>`, reserved by `MessageSender` before delivery.

## Chat rate limit

`rate:<channel>:<chat>`, 30 messages per minute by default (`config/messaging.php`).

## Button payload

The opaque string a button carries back when pressed, encoded by `CallbackDataCodec` within 64 bytes.
It is never stored in the transcript; the transcript keeps the button's label and action.

## Channel capability

A declarative flag (`ChannelCapability`, for example `RemoveButtons`) a channel integration states about
what its provider can do. Flow asks `ChannelRegistry::capabilities()` before using an optional feature.
