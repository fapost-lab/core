# Task 29.1 — Media Domain backend core

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]




Depends on: 13
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 6
Status: Готово
Task №: 29.1

## Goal

Создать backend-ядро Media Domain: схема БД, доменные модели, сервисы, контракты в `fapost/foundation`, per-tenant disk resolution. Без UI, без channel-реализаций.

## Architecture reference

ADR-07 — Media Domain & Channel Asset Cache.

## Migrations

Все таблицы — в tenant schema (не landlord).

### media_blobs

```
$table->ulid('id')->primary();
$table->ulid('tenant_id');
$table->string('content_hash', 64); // SHA-256 hex
$table->string('storage_path');
$table->string('storage_disk');
$table->unsignedBigInteger('size');
$table->string('mime_type');
$table->timestamps();

$table->unique(['tenant_id', 'content_hash']);
$table->index('tenant_id');
```

### media_folders

```
$table->ulid('id')->primary();
$table->ulid('tenant_id');
$table->ulid('parent_id')->nullable();
$table->string('name');
$table->string('path_cache', 1024); // denormalized: "/projects/onboarding"
$table->ulid('created_by')->nullable();
$table->timestamps();

$table->foreign('parent_id')->references('id')->on('media_folders')->nullOnDelete();
$table->index(['tenant_id', 'parent_id']);
$table->index(['tenant_id', 'path_cache']);
```

### media_files

```
$table->ulid('id')->primary();
$table->ulid('tenant_id');
$table->ulid('blob_id');
$table->ulid('folder_id')->nullable();
$table->string('name'); // user-visible
$table->string('kind'); // image | video | audio | document | sticker | other
$table->jsonb('metadata')->default('{}');
$table->ulid('uploaded_by')->nullable();
$table->string('source'); // upload | input_node | api
$table->timestamp('deleted_at')->nullable();
$table->timestamps();

$table->foreign('blob_id')->references('id')->on('media_blobs')->restrictOnDelete();
$table->foreign('folder_id')->references('id')->on('media_folders')->nullOnDelete();
$table->index(['tenant_id', 'folder_id', 'deleted_at']);
$table->index(['tenant_id', 'kind', 'deleted_at']);
$table->index('blob_id');
```

### media_channel_refs

```
$table->ulid('id')->primary();
$table->ulid('blob_id');
$table->ulid('channel_id');
$table->string('provider_file_id');
$table->timestamp('expires_at')->nullable();
$table->timestamp('uploaded_at');

$table->foreign('blob_id')->references('id')->on('media_blobs')->cascadeOnDelete();
$table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();
$table->unique(['blob_id', 'channel_id']);
$table->index('expires_at');
```

### media_file_references

```
$table->ulid('id')->primary();
$table->ulid('media_file_id');
$table->string('reference_type'); // flow_definition | broadcast_template | ...
$table->ulid('reference_id');
$table->jsonb('snapshot'); // {flow_id, flow_name, node_id, node_label}
$table->timestamp('created_at');

$table->foreign('media_file_id')->references('id')->on('media_files')->cascadeOnDelete();
$table->index('media_file_id');
$table->index(['reference_type', 'reference_id']);
```

## Contracts (fapost/foundation)

```
packages/fapost/foundation/src/Media/
  ChannelMediaUploaderInterface.php
  ChannelMediaDownloaderInterface.php
  UploadResult.php
  DownloadResult.php
  MediaKind.php (enum)
  MediaSource.php (enum)
```

```php
namespace FAPost\Foundation\Media;

interface ChannelMediaUploaderInterface
{
    public function channelType(): ChannelTypeEnum;
    public function upload(MediaBlobReadInterface $blob, ChannelInterface $channel): UploadResult;
}

interface ChannelMediaDownloaderInterface
{
    public function channelType(): ChannelTypeEnum;
    public function download(ChannelInterface $channel, string $providerFileId): DownloadResult;
}

final readonly class UploadResult {
    public function __construct(
        public string $providerFileId,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}

final readonly class DownloadResult {
    public function __construct(
        public StreamInterface $stream,
        public string $mimeType,
        public int $size,
        public ?string $originalFilename,
        public ?CarbonImmutable $expiresAt,
    ) {}
}

enum MediaKind: string {
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Sticker = 'sticker';
    case Other = 'other';
}
```

**Note:** `MediaBlobReadInterface` и `ChannelInterface` уже существуют либо должны быть extracted при необходимости. Не тащить Eloquent-модели в foundation.

## Domain services (Core)

```
app/Domains/Media/
  Models/
    MediaBlob.php
    MediaFile.php
    MediaFolder.php
    MediaChannelRef.php
    MediaFileReference.php
  Services/
    MediaService.php           // public CRUD: rename, move, list folder contents
    MediaUploader.php          // upload pipeline: hash compute, dedup check, blob create, file create
    MediaDispatcher.php        // ensureUploadedToChannel + cache management
    MediaIngestor.php          // download from channel + persist (для input-ноды)
    MediaReferenceTracker.php  // парсинг flow_definition.nodes → media_file_references
  Storage/
    TenantMediaDisk.php        // resolve(TenantInterface): Filesystem через Storage::build()
  Registries/
    ChannelMediaUploaderRegistry.php
    ChannelMediaDownloaderRegistry.php
  Enums/
    MediaSourceEnum.php
  Exceptions/
    MediaNotFoundException.php
    MediaUploadFailedException.php
    MediaDeletedException.php
    StorageQuotaExceededException.php
```

### MediaUploader — ключевой сервис

```php
final class MediaUploader
{
    public function uploadFromUploadedFile(
        UploadedFile $file,
        ?MediaFolder $folder,
        string $name,
        MediaSourceEnum $source,
        ?string $uploadedBy,
    ): MediaFile {
        // 1. Compute SHA-256 streaming во время записи в temp
        // 2. Check existing blob: SELECT FROM media_blobs WHERE tenant_id = ? AND content_hash = ?
        // 3a. Если blob есть → создать media_files со ссылкой, удалить temp
        // 3b. Если blob нет:
        //     INSERT ... ON CONFLICT (tenant_id, content_hash) DO NOTHING RETURNING *
        //     если RETURNING пустой — race won by other → SELECT существующий, удалить temp
        //     иначе — переместить temp → final storage_path
        // 4. INSERT media_files
        // 5. Resolve kind по mime_type
    }

    public function storeFromStream(
        StreamInterface $stream,
        string $mimeType,
        ?string $originalFilename,
        ?MediaFolder $folder,
        MediaSourceEnum $source,
    ): MediaFile {
        // То же самое, но из потока (для MediaIngestor)
    }
}
```

### MediaDispatcher — channel cache

```php
final class MediaDispatcher
{
    public function ensureUploadedToChannel(MediaFile $media, Channel $channel): string
    {
        $blob = $media->blob;
        $ref = $this->refs->find($blob->id, $channel->id);

        if ($ref !== null && !$this->isExpired($ref)) {
            return $ref->provider_file_id;
        }

        $uploader = $this->uploaderRegistry->forChannelType($channel->type);
        $result = $uploader->upload($blob, $channel);

        $this->refs->upsert(
            blobId: $blob->id,
            channelId: $channel->id,
            providerFileId: $result->providerFileId,
            expiresAt: $result->expiresAt,
        );

        return $result->providerFileId;
    }

    private function isExpired(MediaChannelRef $ref): bool
    {
        return $ref->expires_at !== null
            && $ref->expires_at->lessThanOrEqualTo($this->clock->now());
    }
}
```

### MediaIngestor — для input-ноды

```php
final class MediaIngestor
{
    public function ingestFromChannel(
        Channel $channel,
        string $providerFileId,
        ?MediaFolder $folder = null,
    ): MediaFile {
        $downloader = $this->downloaderRegistry->forChannelType($channel->type);
        $download = $downloader->download($channel, $providerFileId);

        $media = $this->uploader->storeFromStream(
            stream: $download->stream,
            mimeType: $download->mimeType,
            originalFilename: $download->originalFilename,
            folder: $folder,
            source: MediaSourceEnum::InputNode,
        );

        // Закешировать исходный provider_file_id
        $this->refs->upsert(
            blobId: $media->blob_id,
            channelId: $channel->id,
            providerFileId: $providerFileId,
            expiresAt: $download->expiresAt,
        );

        return $media;
    }
}
```

### MediaReferenceTracker — incremental tracking

```php
final class MediaReferenceTracker
{
    public function trackFlowDefinition(FlowDefinition $definition): void
    {
        // 1. Парсим $definition->nodes (JSONB), извлекаем все media_file_id
        // 2. DELETE FROM media_file_references WHERE reference_type = 'flow_definition' AND reference_id = $definition->id
        // 3. INSERT новые refs со snapshot {flow_id, flow_name, node_id, node_label}
        // 4. Транзакция
    }
}

// Listener:
FlowDefinitionSaved → MediaReferenceTracker::trackFlowDefinition()
```

### TenantMediaDisk

```php
final class TenantMediaDisk
{
    public function resolve(TenantInterface $tenant): Filesystem
    {
        $config = $tenant->getSettings()->mediaStorage; // 'local' | 's3' + creds

        return match ($config['driver']) {
            'local' => Storage::build([
                'driver' => 'local',
                'root' => storage_path("app/tenants/{$tenant->getId()}/media"),
                'visibility' => 'private',
            ]),
            's3' => Storage::build([
                'driver' => 's3',
                'bucket' => $config['bucket'],
                'root' => "tenants/{$tenant->getId()}/media",
                'key' => $config['key'],
                'secret' => $config['secret'],
                'region' => $config['region'],
            ]),
        };
    }
}
```

## Async cleanup job

```
app/Jobs/Media/
  CleanupSoftDeletedMediaJob.php  // cron: ежедневно
```

Логика: `media_files` где `deleted_at < now() - 30d` AND нет references → hard delete row. Если последний `media_file` для blob → удалить `media_blob` + физический файл из storage.

## What NOT to do

- Не помещать Eloquent-модели в `fapost/foundation` (только interfaces / DTOs / enums).
- Не делать pre-upload во все каналы при загрузке файла.
- Не хранить storage_path как user-visible путь (юзер видит folder tree из БД).
- Не реализовывать `ChannelMediaUploaderInterface` для конкретных каналов в этой задаче (это Task 29.2).
- Не делать sync hash compute через `file_get_contents + sha256` — только streaming через `hash_init` + `hash_update_stream`.
- Не использовать `Storage::disk('tenant_X')` через config — только `Storage::build()` в runtime (Octane safety).
- Не разрешать `media_files` без `blob_id`.
- Не делать references tracking через JSONB scan на soft-delete — только incremental на flow_definition save.
- Не пытаться удалить blob если есть хоть один media_file со ссылкой (включая soft-deleted).

## Tests

### Unit

- MediaUploader: dedup hit / dedup miss / race condition (concurrent upload одинакового hash).
- MediaDispatcher: cache hit / cache miss / cache expired / channel uploader exception.
- MediaIngestor: successful download → MediaFile + ref.
- MediaReferenceTracker: парсинг flow_definition.nodes с разными node types.
- TenantMediaDisk: local vs s3 resolution, no global state mutation.

### Integration

- Upload → blob created → media_file created → file accessible via storage.
- Soft-delete → cleanup job через 30d → blob удалён если последняя ссылка.
- Move file between folders → folder_id updated, storage_path не изменился.

## Acceptance criteria

- Все миграции выполняются в tenant schema без ошибок.
- MediaUploader принимает UploadedFile, кладёт в storage, создаёт blob+file rows.
- Дедупликация работает: загрузка одинакового файла дважды → один blob, два media_files.
- MediaDispatcher::ensureUploadedToChannel идемпотентен: повторный вызов не вызывает re-upload.
- TenantMediaDisk::resolve работает в Octane без state leakage между request-ами.
- MediaPreviewRegistry зарегистрирован как singleton, доступен и в Filament (29.4a), и в API (29.3, 29.4b). Регистрация built-in preview-типов (image/video/audio/pdf) + Blade-views — в Task 29.4a.
- phpat-правило: foundation не зависит от Core, Core не имеет media-логики вне `app/Domains/Media`.
- Все unit + integration тесты зелёные.

## Files structure summary

```jsx
packages/fapost/foundation/src/Media/
  ChannelMediaUploaderInterface.php
  ChannelMediaDownloaderInterface.php
  UploadResult.php
  DownloadResult.php
  MediaKind.php

app/Domains/Media/
  Models/{MediaBlob,MediaFile,MediaFolder,MediaChannelRef,MediaFileReference}.php
  Services/{MediaService,MediaUploader,MediaDispatcher,MediaIngestor,MediaReferenceTracker}.php
  Storage/TenantMediaDisk.php
  Registries/{ChannelMediaUploaderRegistry,ChannelMediaDownloaderRegistry}.php
  Enums/MediaSourceEnum.php
  Exceptions/{MediaNotFoundException,MediaUploadFailedException,MediaDeletedException,StorageQuotaExceededException}.php
  Listeners/TrackFlowDefinitionMediaReferences.php
  Preview/
    MediaPreviewRegistry.php  // shared, registered in MediaServiceProvider
    MediaPreviewType.php       // value object
  MediaServiceProvider.php

app/Jobs/Media/
  CleanupSoftDeletedMediaJob.php

database/migrations/tenant/
  YYYY_MM_DD_create_media_blobs_table.php
  YYYY_MM_DD_create_media_folders_table.php
  YYYY_MM_DD_create_media_files_table.php
  YYYY_MM_DD_create_media_channel_refs_table.php
  YYYY_MM_DD_create_media_file_references_table.php
```