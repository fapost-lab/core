# Task 29.4b — Vue media picker (builder integration)

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]





Depends on: 29.3, 17
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 6
Status: Готово
Task №: 29.42

## Goal

Vue-компонент media picker, встраиваемый в node config panel Vue Flow билдера (Task 17). Позволяет юзеру: навигировать по папкам, выбирать файл из существующих, загружать новый прямо из билдера.

## Component contract

```
<MediaPicker
  v-model="node.config.media_file_id"
  :kind-filter="['image']"  // или ['document', 'video', 'audio'] согласно типу send_message
  :allow-upload="true"
  :provider-warnings-for="['telegram']"  // показывать warnings для активных каналов ассистента
  @select="onMediaSelected"
/>
```

Value в v-model — `media_file_id` (string ULID) или null.

## Preview rendering

Preview-тип файла резолвится на сервере через `MediaPreviewRegistry` (см. Task 29.4a). API возвращает в ответе `GET /api/v1/media/files/{id}` поле `preview`:

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

Client-side логика:

- `kind === 'image'` → `<img :src="signed_url">`
- `kind === 'video'` → `<video controls :src="signed_url">`
- `kind === 'audio'` → `<audio controls :src="signed_url">`
- `requires_external_render === true` (PDF, custom plugin types) → `<iframe :src="/api/v1/media/files/{id}/preview">` — сервер рендерит Blade view с нужными assets (например pdf.js)
- Fallback (неизвестный kind) → generic file card с icon из `icon_heroicon`

Сложных preview (PDF, custom plugin types) не дублируем на клиенте — это явный контракт: логика рендера живёт в Preview Registry, Vue интерпретирует флаг `requires_external_render`.

## UX flow

### Trigger

Нода send_message с типом image/video/document → в config panel поле «Файл»:

- Если файл не выбран: button «Выбрать файл».
- Если файл выбран: thumbnail + name + button «Изменить» / «Очистить».

### Picker modal

Клик на «Выбрать файл» → modal с:

- **Левая панель:** tree папок (collapsible).
- **Top bar:** breadcrumbs текущей папки + search input.
- **Center:** grid файлов (thumbnail + name). Папки видны всегда; файлы фильтруются по `kind-filter` из props.
- **Bottom bar:** «Загрузить новый файл» (drag-drop zone или button) + «Отмена» / «Выбрать».

### Upload from picker

- Drag файл в picker → upload в текущую папку.
- Прогресс bar.
- После upload → файл автоматически выделен, юзер может сразу нажать «Выбрать».
- Если dedup hit → toast «Этот файл уже есть, использован существующий».

### Provider warnings

- При hover на файл который превышает лимит активного канала → tooltip «Файл больше 50MB, не отправится через Telegram как документ».
- При выборе такого файла → confirmation: «Файл превышает лимит Telegram. Сообщение не доставится. Продолжить?».

## API integration

Использует endpoints из Task 29.3:

- `GET /api/v1/media/picker/contents?folder_id=...&kind=image` — навигация.
- `POST /api/v1/media/files` — upload.
- `GET /api/v1/media/folders` — для tree (один раз при mount, потом обновления через WebSocket или refetch).

## State management

- Pinia store `mediaPickerStore` (отдельный, не глобальный media store):
    - currentFolderId
    - cachedContents (per folder)
    - selectedFileId
    - uploadProgress
- Кеш content per folder: при возврате в посещённую папку — мгновенный рендер.
- Invalidation: после upload / delete / move — invalidate affected folders.

## Component structure

```
resources/js/components/media/
  MediaPicker.vue              # entry point, embed-able
  MediaPickerModal.vue         # modal wrapper
  MediaFolderTree.vue          # left sidebar tree
  MediaBreadcrumbs.vue
  MediaFileGrid.vue
  MediaFileCard.vue            # одна карточка файла с thumbnail
  MediaUploadDropZone.vue
  MediaProviderWarning.vue     # tooltip / inline warning

resources/js/stores/
  mediaPickerStore.ts

resources/js/composables/
  useMediaApi.ts               # axios wrapper над media endpoints
  useMediaUpload.ts            # multipart upload с прогрессом
```

## Integration with Task 17 builder

- В `NodeConfigPanel.vue` (часть Task 17) для send_message ноды с `media_*` типами:
    - Импортировать `MediaPicker`.
    - Bind на `node.config.media_file_id`.
    - Передать `kind-filter` согласно type.
    - Передать `provider-warnings-for` из ассистента (массив активных channel types).
- Для input ноды с `expected_type: 'file'` picker не используется (input принимает файл от пользователя в runtime).

## What NOT to do

- Не дублировать API логику в Vue — только через REST endpoints.
- Не делать picker глобальным компонентом приложения — это локальный component, embed-able.
- Не использовать Vuex / другой store — только Pinia.
- Не парсить mime/kind на клиенте — все решения с сервера.
- Не показывать `storage_path` / `storage_disk`.
- Не позволять выбор файла, помеченного soft-deleted (filtered на API уровне).
- Не блокировать UI на upload — async с прогрессом.
- Не делать кастомные thumbnails на клиенте — используем `thumbnail_url` с сервера (приходит после async generation).

## Tests

### Component (Vitest + Vue Test Utils)

- MediaPicker рендерит дерево + grid.
- Klick по папке → contents обновляется.
- Upload → API called → grid refreshed.
- Selection → v-model emits.
- kind-filter → файлы фильтруются.
- Provider warning отображается при превышении лимита.

### E2E (если есть Playwright/Cypress)

- Открыть билдер → создать send_message ноду → выбрать файл через picker → сохранить flow → проверить что media_file_id в snapshot.

## Acceptance criteria

- MediaPicker встраивается в node config panel.
- Tree-навигация по папкам работает.
- Upload из picker работает с прогрессом.
- Provider warnings отображаются согласно активным каналам ассистента.
- v-model корректно эмитит media_file_id.
- Кеш папок переиспользуется при возврате в посещённую папку.
- Все component тесты зелёные.

## Files

```
resources/js/components/media/
  MediaPicker.vue
  MediaPickerModal.vue
  MediaFolderTree.vue
  MediaBreadcrumbs.vue
  MediaFileGrid.vue
  MediaFileCard.vue
  MediaUploadDropZone.vue
  MediaProviderWarning.vue

resources/js/stores/mediaPickerStore.ts
resources/js/composables/useMediaApi.ts
resources/js/composables/useMediaUpload.ts

tests/js/components/media/
  MediaPicker.spec.ts
  MediaFileGrid.spec.ts
  ...
```