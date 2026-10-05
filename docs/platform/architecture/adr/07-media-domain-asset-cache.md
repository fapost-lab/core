# ADR-07 — Media Domain & Channel Asset Cache

> **Superseded in part (2026-10).** `MediaSource` has a fourth case, `Conversation`
> (`app/Domains/Media/Enums/MediaSource.php`): files ingested from the conversation transcript (inbound media captured by
> `FetchConversationMediaJob`). Such files are filtered out of the media library listing
> (`MediaService::listFolderContents`). The source enum below is therefore `upload | input_node | api | conversation`.

## Status

Accepted · Апрель 2026

## Context

До текущего момента flow engine оперирует только текстовыми сообщениями. С введением Vue Flow конструктора (Task 17) и расширением send_message handler-а на media-типы (image, video, document, audio) появляется потребность в управлении файлами:

- **Загрузка** файлов тенантом (через Filament-админку и через picker в билдере).
- **Хранение** файлов в per-tenant изолированном storage (local на self-hosted, S3 на SaaS).
- **Отправка** файлов через канальные провайдеры (Telegram Bot API, в будущем WhatsApp Cloud API).
- **Приём** файлов от пользователей через input ноду (download + persist + linkage).
- **User-managed папки** для организации медиатеки.
- **References tracking** между media-файлами и flow_definitions для безопасного удаления.

Наивная реализация (отправлять файл bytes-ами при каждой отправке) приводит к latency на крупных файлах и насыщает provider rate limits. Pre-upload во все каналы при загрузке файла создаёт fan-out проблему, не выживает при добавлении/удалении каналов и игнорирует TTL у WhatsApp media (30 дней). Хранение storage path-ов в виде user-visible folder structure делает rename/move папок нетранзакционным.

## Decision

### 1. Lazy upload + per-channel cache

Файл грузится в канального провайдера при **первой** отправке через этот канал. Полученный provider_file_id кешируется в БД. Последующие отправки того же файла через тот же канал — без upload, только provider_file_id.

- Cache miss или `expires_at <= now()` → re-upload.
- Telegram: `expires_at = NULL` (long-lived).
- WhatsApp: `expires_at = uploaded_at + 30 дней`.
- Никакого pre-upload во все каналы при загрузке файла.

### 2. Blob/file разделение

Две сущности:

- **`media_blobs`** — физический файл в storage. Идентифицируется по `(tenant_id, content_hash)` UNIQUE. Один blob на N media_files.
- **`media_files`** — пользовательская сущность (имя, папка, метаданные). FK → `media_blobs.id`.

Дедупликация по SHA-256 при upload (sync, hash compute streaming). Channel cache привязан к **blob_id**, не к media_file_id — duplicate files переиспользуют один upload в канал.

### 3. JSON ноды хранит media_file_id

В `flow_definitions.nodes` для send_message и input нод хранится `media_file_id`, а не `blob_id`. Внутри сервисов резолвится `media_file → blob → channel_ref`. Soft-delete media_file даёт engine осмысленный сигнал «эта ссылка битая» и фейлит ноду через fallback message.

### 4. User-managed папки в БД

Папки — Eloquent сущности (`media_folders` table), не пути в FS. Дерево через self-FK `parent_id`. Перемещение/переименование папки — `UPDATE` одной строки, транзакционно.

Storage layout полностью отвязан от user-visible structure: физически файл лежит как `tenants/{tenant_id}/media/{ulid}.{ext}`, юзер видит дерево из БД.

### 5. Per-tenant disk через runtime resolve

`TenantMediaDisk::resolve(TenantInterface $tenant): Filesystem` создаёт disk через `Storage::build()` без регистрации в global config. Octane-safe (нет мутации global state).

Конфигурация диска лежит в `tenant.settings.media_storage`: driver (`local` / `s3`), bucket/root path, credentials.

### 6. Incremental references tracking

При сохранении `flow_definition` парсится JSON nodes, extract все `media_file_id` references → upsert в `media_file_references` table. Soft-delete media_file → простой `SELECT FROM media_file_references WHERE media_file_id = ?`, не scan JSONB.

Snapshot ссылки включает контекст (flow_id, flow name, node label) для UI без N+1 запросов.

### 7. Soft-delete + async cleanup

UI delete → `media_files.deleted_at = now()`. Если есть references — UI показывает их в modal, hard delete доступен только через явное override.

Cron job: `media_files` где `deleted_at < now() - 30d` AND `references = 0` → hard delete. Если последний `media_file` для blob → удаление `media_blob` + физического файла из storage.

## Consequences

### Positive

- Channel upload cost amortized: дорогой upload крупного файла происходит один раз на (blob, channel).
- Storage экономится через blob-level дедупликацию.
- User-managed folder rename — O(1) операция.
- Soft-delete не ломает активные сессии немедленно — engine получает осмысленный fail.
- Per-tenant disk resolve через `Storage::build()` совместим с Octane.
- Контракты (`ChannelMediaUploaderInterface`, `ChannelMediaDownloaderInterface`) в `fapost/foundation` позволяют плагинам новых каналов добавлять media-поддержку без зависимости от Core.

### Negative / Trade-offs

- Дополнительный JOIN при отправке (`media_file → blob → channel_ref`). Приемлемо.
- Усложнённая модель (5 таблиц вместо 1). Оправдано долгой жизнью контракта.
- WhatsApp 30d TTL требует periodic re-upload — handled через `expires_at` check на каждой отправке.
- Sync hash compute при upload — на больших файлах +CPU, но это часть I/O (`hash_update_stream`), не блокирующая операция.
- Incremental references tracking создаёт coupling между flow_definition save и media domain — приемлемо, защищено через event listener.

## Schema (overview)

```
media_blobs:
  id (ULID), tenant_id, content_hash (SHA-256), storage_path,
  storage_disk, size, mime_type, created_at
  UNIQUE (tenant_id, content_hash)

media_files:
  id (ULID), tenant_id, blob_id (FK → media_blobs),
  folder_id (nullable FK → media_folders), name, kind (enum),
  metadata (JSONB), uploaded_by (nullable FK → users),
  source (enum: upload | input_node | api), deleted_at, timestamps

media_folders:
  id (ULID), tenant_id, parent_id (nullable self-FK), name,
  path_cache (denormalized), created_by, timestamps

media_channel_refs:
  id (ULID), blob_id (FK → media_blobs), channel_id (FK → channels),
  provider_file_id, expires_at (nullable), uploaded_at
  UNIQUE (blob_id, channel_id)

media_file_references:
  id (ULID), media_file_id (FK), reference_type (enum),
  reference_id (ULID), snapshot (JSONB), created_at
  INDEX (media_file_id), INDEX (reference_type, reference_id)
```

## Contracts (fapost/foundation)

```
ChannelMediaUploaderInterface
  channelType(): ChannelTypeEnum
  upload(MediaBlob $blob, Channel $channel): UploadResult

ChannelMediaDownloaderInterface
  channelType(): ChannelTypeEnum
  download(Channel $channel, string $providerFileId): DownloadResult

UploadResult { providerFileId: string, expiresAt: ?CarbonImmutable }
DownloadResult { stream, mimeType, size, originalFilename, expiresAt }

MediaKind enum: image | video | audio | document | sticker | other
```

## Implementation tasks

- **Task 29** — Media Domain (parent)
- **Task 29.1** — Backend core (migrations, models, services, contracts)
- **Task 29.2** — Telegram media adapter + send_message handler rework
- **Task 29.3** — Media REST API
- **Task 29.4a** — Filament MediaResource
- **Task 29.4b** — Vue media picker (integrates into Task 17 builder)

## Related

- Builds on ADR-05 (fapost/foundation as extension boundary)
- Affects existing send_message handler (Task 13) — refactor in Task 29.2
- Integrates with Flow constructor UI (Task 17) via Vue picker (29.4b)

---

## Связано с

- [[conversation-logging]] — хранение медиа в диалогах
- [[10-message-pipeline]] — пайплайн обработки сообщений
- [[01-send-message]] — нода send_message с media content_type
- [[04-assistant-domain]] — Assistant Domain (владелец каналов для media upload)
- [[05-contacts]] — Contact Domain (input-нода сохраняет медиа контактов)