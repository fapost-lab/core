# Task 29.4a — Filament MediaResource

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]





Depends on: 29.3
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 6
Status: Готово
Task №: 29.41

## Goal

Добавить пункт меню **«Медиа»** в Filament-админку тенанта. Полнофункциональный file manager: tree-навигация по папкам, upload, rename, move, delete, references inspection.

## Approach

Filament Resource поверх готового Media REST API (Task 29.3) или прямо через `MediaService`. Внутри Filament — это просто PHP, можно дёргать сервисы напрямую без HTTP. Но логика same as API endpoints (валидация, авторизация).

**Решение:** Filament Resource дёргает `MediaService` напрямую. API из 29.3 живёт параллельно для Vue picker. Дублирования логики нет — оба идут через service layer.

## UX Reference

**`tomatophp/filament-media-manager`** используется как референс UX, не как зависимость. Полезные паттерны для копирования:

- Левый sidebar с tree папок + breadcrumbs над таблицей.
- Grid файлов с thumbnail + hover preview.
- Live preview модалка при выборе файла (с file info + actions).
- Видео/аудио/PDF preview через registry кастомных view-ов per extension.

Исходники для подсматривания UI-решений: [https://github.com/tomatophp/filament-media-manager](https://github.com/tomatophp/filament-media-manager)

**НЕ копировать:** их доменную модель (Spatie Media Library), polymorphic owner pattern, MediaManagerInput/Picker компоненты. Только визуал и UX-паттерны.

## Preview System

Инспирация: `MediaManagerType` из tomatophp. Реализуем свой реестр preview, который рендерит Blade view per file extension. Используется и в Filament, и в Vue picker (через API).

### MediaPreviewRegistry

`app/Domains/Media/Preview/MediaPreviewRegistry.php`

```php
final class MediaPreviewRegistry
{
    /** @var array<string, MediaPreviewType> */
    private array $byExtension = [];

    public function register(MediaPreviewType $type): void
    {
        foreach ($type->extensions as $ext) {
            $this->byExtension[strtolower($ext)] = $type;
        }
    }

    public function resolve(MediaFile $file): ?MediaPreviewType
    {
        $ext = strtolower(pathinfo($file->name, PATHINFO_EXTENSION));
        return $this->byExtension[$ext] ?? null;
    }
}

final readonly class MediaPreviewType
{
    public function __construct(
        public array $extensions,
        public string $view,
        public string $iconHeroicon,
        public array $assets = [],
    ) {}
}
```

### Регистрация built-in preview-типов

`MediaServiceProvider::boot()`:

```php
$registry = $this->app->make(MediaPreviewRegistry::class);
$registry->register(new MediaPreviewType(
    extensions: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
    view: 'media.preview.image',
    iconHeroicon: 'photo',
));
$registry->register(new MediaPreviewType(
    extensions: ['mp4', 'webm', 'mov'],
    view: 'media.preview.video',
    iconHeroicon: 'video-camera',
));
$registry->register(new MediaPreviewType(
    extensions: ['mp3', 'ogg', 'wav', 'm4a'],
    view: 'media.preview.audio',
    iconHeroicon: 'speaker-wave',
));
$registry->register(new MediaPreviewType(
    extensions: ['pdf'],
    view: 'media.preview.pdf',
    iconHeroicon: 'document-text',
    assets: ['https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.3.136/pdf.min.js'],
));
```

### Blade views

```
resources/views/media/preview/
  image.blade.php    — <img> с lazy load
  video.blade.php    — <video controls> с poster (thumbnail если есть)
  audio.blade.php    — <audio controls>
  pdf.blade.php      — pdf.js viewer
  fallback.blade.php — generic icon + filename + download button
```

Каждая view принимает `$file` (MediaFile) и `$signedUrl` (signed URL для доступа к содержимому файла).

### Использование в Filament

```php
// MediaResource/Pages/ViewMediaFile.php
protected function getViewData(): array
{
    $previewType = $this->previewRegistry->resolve($this->record);
    return [
        'previewView' => $previewType?->view ?? 'media.preview.fallback',
        'previewAssets' => $previewType?->assets ?? [],
        'signedUrl' => $this->mediaService->signedUrl($this->record),
    ];
}
```

### Использование в Vue picker (Task 29.4b)

API endpoint `/api/v1/media/files/{id}` возвращает поле `preview`:

```json
{
  "preview": {
    "kind": "video",
    "signed_url": "...",
    "icon_heroicon": "video-camera",
    "requires_external_render": false
  }
}
```

Vue picker рендерит image/video/audio через нативные HTML-теги (signed_url напрямую). PDF — iframe на серверный endpoint, который рендерит Blade-view с pdf.js. Так избегаем дублирования pdf.js логики на клиенте.

### Extension point

Plugins/Solutions могут регистрировать свои preview-типы через `MediaPreviewRegistry::register()` в их ServiceProvider-ах. Это open extension point — закрытой системы нет.

## Components

### MediaResource

`app/Filament/Resources/MediaResource.php`

- Navigation group: «Контент» (или новая группа «Медиа», как в продукте принято).
- Navigation icon: `heroicon-o-photo` или `heroicon-o-folder`.
- Title: «Медиа».

### Pages

- `MediaResource/Pages/ListMedia.php` — главная страница с tree-навигацией + table
- `MediaResource/Pages/ViewMediaFile.php` — детали файла (read-only с actions)

### Tree navigation

- Левый sidebar: tree папок (collapsible). Активная папка highlighted.
- Breadcrumbs над таблицей: `Корень / Проекты / Онбординг`.
- Клик по папке → table обновляется (Livewire reactive).
- Кнопка «Создать папку» в текущей parent папке.
- Tree рендерится через `awcodes/filament-tree` если подходит, или простой кастомный Livewire-компонент с recursive partials.

### Table

Колонки:

- Превью (image thumbnail если kind=image, иначе icon по kind).
- Имя (clickable → ViewMediaFile).
- Тип (badge: image/video/document...).
- Размер (human readable).
- Использование (badge с count references; click → modal с ссылками на flows).
- Дата загрузки.
- Загружен (user).

Filters:

- Тип (kind).
- Soft-deleted (toggle).
- Search по name.

Actions (per row):

- Переименовать (modal с inline form).
- Переместить (modal с tree-picker папок).
- Удалить (soft-delete с confirmation; если references > 0 → modal предупреждает).
- Восстановить (для soft-deleted).
- Удалить навсегда (только для админа, для soft-deleted с references=0; иначе error).

Bulk actions:

- Переместить выбранные.
- Soft-delete выбранные.

Header actions:

- Загрузить файлы (modal с drag-and-drop, multiple, прогресс per file).
- Создать папку.

### References modal

Клик на «Использование» в строке → modal:

- Список references со snapshot data: flow name, node label, link на flow editor (Task 17).
- Группировка по reference_type если в будущем будут не только flow_definitions.

### Upload UX

- Drag-and-drop в любое место списка → upload в текущую папку.
- Прогресс per file (Livewire).
- После upload → toast с результатом, если был dedup hit → информативный текст («Этот файл уже есть в библиотеке, использован существующий»).
- Provider warnings (из API) показываются как badges на файле в таблице («⚠ Превышает лимит Telegram для документов»).

## Permissions

- View: `media.view`.
- Manage: `media.manage`.
- Hard delete: дополнительно `media.force_delete` (опционально).

## What NOT to do

- Не делать загрузку через сторонний плагин (Spatie / Curator) — мы строим свой Resource поверх своих моделей.
- Не дёргать API endpoints из Filament Resource — использовать `MediaService` напрямую.
- Не дублировать логику валидации — переиспользовать из service layer.
- Не делать blocking thumbnail generation — async, lazy load в UI.
- Не показывать `storage_path` / `storage_disk` юзеру.
- Не позволять кросс-tenant операции (защищено middleware + policy, но и в Resource — `getEloquentQuery` scoped по tenant).

## Tests

### Feature (Filament-specific)

- Список папок и файлов отображается scoped по tenant.
- Upload через action → файл создан, появляется в таблице.
- Rename / move / soft-delete / restore через actions.
- References modal показывает корректные ссылки.
- Bulk move работает.

## Acceptance criteria

- Пункт меню «Медиа» доступен в Filament tenant panel.
- Tree-навигация по папкам работает.
- Upload, rename, move, delete, restore — через UI.
- References modal показывает использование файла.
- Provider warnings отображаются.
- Авторизация через permissions.
- Все feature тесты зелёные.

## Files

```
app/Filament/Resources/
  MediaResource.php
  MediaResource/
    Pages/
      ListMedia.php
      ViewMediaFile.php
    Widgets/
      FolderTree.php
      ReferencesModal.php
    Actions/
      UploadFilesAction.php
      CreateFolderAction.php
      MoveFileAction.php

app/Domains/Media/Preview/
  MediaPreviewRegistry.php
  MediaPreviewType.php

resources/views/media/preview/
  image.blade.php
  video.blade.php
  audio.blade.php
  pdf.blade.php
  fallback.blade.php
```