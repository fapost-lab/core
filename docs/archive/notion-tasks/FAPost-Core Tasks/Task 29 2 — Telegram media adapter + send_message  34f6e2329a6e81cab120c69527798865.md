# Task 29.2 — Telegram media adapter + send_message rework

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]




Depends on: 29.1, 16
Domain: Messaging
Phase: 2 — Flow Engine
Sprint: 6
Status: Готово
Task №: 29.2

## Goal

Реализовать `ChannelMediaUploaderInterface` и `ChannelMediaDownloaderInterface` для Telegram. Перевести существующий `send_message` handler с прямой передачи URL на использование `MediaDispatcher`. Обеспечить input-ноду работой с файлами через `MediaIngestor`.

## Context

Task 16 (Telegram MVP) уже содержит `TelegramBotApiClient` с методами `sendPhoto`, `sendDocument`. Сейчас они принимают URL или path как аргумент. После 29.2 они принимают **provider_file_id**, полученный из `MediaDispatcher::ensureUploadedToChannel()`.

## Components

### TelegramMediaUploader

`app/Domains/Messaging/Telegram/Media/TelegramMediaUploader.php`

Реализует `ChannelMediaUploaderInterface`. Использует `TelegramBotApiClient::sendDocument`/`sendPhoto`/`sendVideo`/`sendAudio` к **служебному chat_id** (например, владелец бота или специальный «storage» chat) — Telegram возвращает `file_id` после загрузки.

**Альтернатива (предпочтительная):** использовать `getFile`-after-upload pattern — отправить файл в docs/служебный chat, получить `file_id` из ответа. Telegram не имеет prepare-upload endpoint; единственный способ получить `file_id` — фактическая отправка.

**Решение архитектора:** на этапе 29.2 решить — отдельный «storage chat» (требует конфигурации владельца) или upload-on-first-send (ленивая загрузка происходит при первой реальной отправке пользователю, file_id кешируется по факту первой отправки).

**Рекомендация:** **upload-on-first-send без отдельного storage chat.** `TelegramMediaUploader::upload()` отправляет файл реальному получателю (chat_id из контекста OutboundMessage), парсит response → file_id. Это нарушает чистоту контракта (uploader получает chat_id, что нелогично), но избегает служебного chat и его настройки.

**Финальное решение к моменту реализации:** контракт `ChannelMediaUploaderInterface::upload()` нужно расширить optional context-аргументом `UploadContext { ?targetChatId }`. Telegram-реализация требует chatId; будущие каналы могут игнорировать.

### TelegramMediaDownloader

`app/Domains/Messaging/Telegram/Media/TelegramMediaDownloader.php`

Реализует `ChannelMediaDownloaderInterface`. Логика:

1. `TelegramBotApiClient::getFile(fileId)` → `{ file_path }`
2. HTTP GET `https://api.telegram.org/file/bot<TOKEN>/<file_path>` → bytes stream
3. Возвращает `DownloadResult { stream, mimeType (guess из file_path), size, originalFilename: null, expiresAt: null }`

**Note:** Telegram file_path действителен ~1 час, но мы скачиваем сразу — TTL не критичен. После download всё лежит у нас.

### send_message handler rework

`app/Domains/Flow/Handlers/SendMessageHandler.php` (существующий)

Изменения:

- Поле `media_file_id` в node config (новое; раньше был `media_url` или `media_path`).
- При выполнении: если `media_file_id` указан →
    - `$mediaFile = $this->mediaService->find($mediaFileId)`
    - Если soft-deleted → fail node с `MediaDeletedException`, fallback message из node config.
    - `$providerFileId = $this->mediaDispatcher->ensureUploadedToChannel($mediaFile, $channel)`
    - `$payload = SendPhotoDto / SendDocumentDto / SendVideoDto` с `media: $providerFileId` (Telegram принимает file_id вместо URL).
- Если `media_file_id` отсутствует → текстовое сообщение, как раньше.

**Backward compat:** старого формата (URL) в существующих flow_definitions быть не должно — handler ещё не отправлял реальные медиа. Но **миграция flow_definitions**: добавить validation в save, что `media_url` / `media_path` поля не допускаются. Если в snapshot-ах есть legacy формат — добавить data migration script (вероятно не нужен, если фича медиа ещё не использовалась).

### input handler с file типом

`app/Domains/Flow/Handlers/InputHandler.php` (существующий)

Добавить поддержку `expected_type: 'file'` (и подтипы: image / video / document):

- При получении сообщения с media: извлечь `provider_file_id` из payload.
- `$mediaFile = $this->mediaIngestor->ingestFromChannel($channel, $providerFileId, folder: null)`
- Сохранить в `flow.{variable_name}` → `{ media_file_id: $mediaFile->id, name: $mediaFile->name, kind: $mediaFile->kind }`.
- Default folder для input-ноды: автоматическая папка `Inbox` (создаётся lazy при первом ingest, владелец — тенант, не пользователь).

### Registration

`app/Domains/Messaging/Telegram/TelegramServiceProvider.php`:

```php
$this->app->tag([TelegramMediaUploader::class], 'channel.media.uploaders');
$this->app->tag([TelegramMediaDownloader::class], 'channel.media.downloaders');
```

`MediaServiceProvider` собирает tagged bindings в `ChannelMediaUploaderRegistry` / `ChannelMediaDownloaderRegistry`.

## What NOT to do

- Не отправлять файл bytes-ами на каждой отправке — только через provider_file_id после первой загрузки.
- Не использовать `getFile` для проверки валидности `file_id` перед отправкой — Telegram отдаст ошибку при отправке если file_id невалиден, обработаем там.
- Не обходить `MediaDispatcher` напрямую через TelegramBotApiClient в handler-е.
- Не создавать «storage chat» если выбран upload-on-first-send путь.
- Не парсить media payload вручную в handler — использовать `TelegramInboundNormalizer` (уже из Task 16).
- Не игнорировать soft-deleted media — fail node с осмысленной ошибкой.
- Не рефакторить весь TelegramSender — только методы для media.

## Tests

### Unit

- TelegramMediaUploader::upload — mock TelegramBotApiClient → корректный UploadResult.
- TelegramMediaDownloader::download — mock HTTP → корректный DownloadResult.
- SendMessageHandler с media_file_id: cache hit (без re-upload), cache miss (с upload), soft-deleted file (fail).
- InputHandler с file: ingest → flow state populated.

### Integration

- End-to-end: upload файла через MediaUploader → отправка через handler → второй раз handler идёт по cache hit (verified через mock invocation count).
- End-to-end: incoming message с photo → input handler → media_file создан → blob создан → channel_ref закеширован.

## Acceptance criteria

- Telegram channel может отправлять image/video/document/audio через media_file_id.
- Повторная отправка того же файла → без HTTP upload в Telegram (verified в test через mock).
- Input-нода с file типом сохраняет файл в media domain, blob создан, ref закеширован.
- Soft-deleted media → handler fails node с fallback message.
- phpat-правило: handler не зависит напрямую от TelegramBotApiClient (только через MessageSender + MediaDispatcher).

## Files

```
app/Domains/Messaging/Telegram/Media/
  TelegramMediaUploader.php
  TelegramMediaDownloader.php

app/Domains/Flow/Handlers/
  SendMessageHandler.php (rework)
  InputHandler.php (extend file support)

packages/fapost/foundation/src/Media/
  UploadContext.php (new)
```