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
reviewed_at: 2026-10-05
---
# Channels glossary

## Channel

An assistant's transport endpoint on one messenger. Informal synonyms: bot, integration.

## Channel type

`ChannelTypeEnum` (`telegram`, `whatsapp`): the kind of channel. Not the same enum as Contact's
`PlatformEnum` (`telegram`, `whatsapp`, `email`), although the values overlap.

## Channel integration definition

The set of class names — sender, registrar, adapter — that serve one channel type, registered
with the tag `channels.integration`.

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
