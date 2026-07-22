# Channel connectors — Telegram MVP

> Архив Notion. Актуальная документация: [[10-message-pipeline]]


Domain: Messaging
Phase: 3 — Messaging Pipeline
Status: Готово
Task №: 16

## Goal

Реализовать Telegram channel connector: входящий путь (нормализация, верификация подписи) и исходящий путь (отправка через Bot API). Регистрация webhook привязана к lifecycle событиям `Channel`.

## Depends on

Task 08 (ChannelAdapterInterface, webhook routing), Task 16 (MessageSenderInterface, ProviderSenderInterface, OutboundMessage, DeliveryResult)

## Scope

Задача покрывает только Telegram. WhatsApp connector — отдельная задача.

## Architectural decisions

**TelegramBotApiClient → app/Domains/Messaging/Telegram/.** Не отдельный пакет, не generic SDK. Живёт в Core рядом с `TelegramSender`.

**Endpoint-driven рост.** `TelegramBotApiClient` содержит только реально используемые методы. Не проектируется весь Telegram API заранее. Добавляем методы по мере появления use-case.

**DTO правило.** DTO обязателен если метод имеет 3+ аргументов или любой опциональный параметр. Скалярные аргументы только для вырожденных методов (`deleteWebhook`, `getMe`).

**Разделение ответственности.**

- `TelegramBotApiClient` — HTTP вызовы, retry, timeout, парсинг ошибок Telegram
- `TelegramSender` — маппинг `OutboundMessage.payload.type` → метод клиента

**`request()` приватный.** Транспорт не является частью публичного API. Запрещён генерический `send(method, payload)` наружу.

**WebhookRegistrar → lifecycle события.** `TelegramWebhookRegistrar` вызывается из Eloquent событий `Channel` (created/updated/deleted), не из artisan-команды.

## Components

**TelegramBotApiClient** (app/Domains/Messaging/Telegram/TelegramBotApiClient.php)

Публичные методы MVP: `sendMessage(SendMessageDto)`, `sendPhoto(SendPhotoDto)`, `sendDocument(SendDocumentDto)`, `setWebhook(SetWebhookDto)`, `deleteWebhook(string $url)`. Внутри — `private request(string $method, array $payload): array`. HTTP-клиент — Laravel HTTP facade. Retry на 5xx и сетевые ошибки.

**TelegramSender** (app/Domains/Messaging/Telegram/TelegramSender.php)

Реализует `ProviderSenderInterface`. `channelType(): string` возвращает `'telegram'`. `deliver(OutboundMessage)` маппирует `payload.type` → метод клиента. Возвращает `DeliveryResult`.

**TelegramInboundNormalizer** (app/Domains/Messaging/Telegram/TelegramInboundNormalizer.php)

Нормализует Telegram Update → `IncomingMessage` (chat_id, text, message_id, update_id как idempotency key). Поддерживает message и callback_query.

**TelegramSignatureVerifier** (app/Domains/Messaging/Telegram/TelegramSignatureVerifier.php)

Проверяет `X-Telegram-Bot-Api-Secret-Token` без обращения к БД. Принимает `secret_token` из Redis webhook registry payload.

**TelegramAdapter** (app/Domains/Messaging/Telegram/TelegramAdapter.php)

Реализует `ChannelAdapterInterface`. Оркестрирует `TelegramSignatureVerifier` + `TelegramInboundNormalizer`. Возвращает `IncomingMessage` для дальнейшего dispatch.

**WebhookRegistrarInterface** (packages/fapost/foundation/src/Channel/WebhookRegistrarInterface.php)

```
interface WebhookRegistrarInterface
{
    public function register(Channel $channel): void;
    public function deregister(Channel $channel): void;
}
```

**TelegramWebhookRegistrar** (app/Domains/Messaging/Telegram/TelegramWebhookRegistrar.php)

Реализует `WebhookRegistrarInterface`. Вызывается из `Channel` model observer при created/updated/deleted. Использует `TelegramBotApiClient::setWebhook()` / `deleteWebhook()`.

## DTO structure

```
app/Domains/Messaging/Telegram/Dto/
  SendMessageDto.php     // chat_id, text, parse_mode?, reply_markup?
  SendPhotoDto.php       // chat_id, photo, caption?
  SendDocumentDto.php    // chat_id, document, caption?
  SetWebhookDto.php      // url, secret_token, allowed_updates?
```

## File structure

```
packages/fapost/foundation/src/Channel/
  WebhookRegistrarInterface.php

app/Domains/Messaging/Telegram/
  TelegramBotApiClient.php
  TelegramSender.php
  TelegramAdapter.php
  TelegramInboundNormalizer.php
  TelegramSignatureVerifier.php
  TelegramWebhookRegistrar.php
  Dto/
    SendMessageDto.php
    SendPhotoDto.php
    SendDocumentDto.php
    SetWebhookDto.php

app/Observers/
  ChannelObserver.php
```

## What NOT to do

- Не делать `generic send(string $method, array $payload)` наружу `TelegramBotApiClient`
- Не реализовывать весь Telegram API заранее — только реальные use-case
- Не дробить на отдельные SendMessageService, SendPhotoService и т..п.
- Не делать artisan-команду для регистрации webhook — только через observer
- Не обращаться к БД при верификации подписи — только Redis
- Не выносить `TelegramBotApiClient` в отдельный пакет
- Не использовать скалярные аргументы в методах с 3+ аргументами или опциональными — только DTO

## Acceptance criteria

- Incoming Telegram update проходит верификацию подписи без БД
- Нормализация message/callback_query → `IncomingMessage`
- `TelegramSender` успешно маппирует text/photo/document
- Webhook регистрируется автоматически при создании/изменении `Channel`
- Webhook удаляется при удалении `Channel`
- `TelegramBotApiClient` не имеет публичного `send(method, payload)`