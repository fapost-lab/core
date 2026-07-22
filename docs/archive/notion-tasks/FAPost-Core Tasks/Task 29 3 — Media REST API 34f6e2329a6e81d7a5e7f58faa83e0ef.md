# Task 29.3 — Media REST API

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]




Depends on: 29.1
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 6
Status: Готово
Task №: 29.3

## Goal

Построить REST API над Media Domain. Это единственный contract, через который работают и Filament Resource (29.4a), и Vue media picker (29.4b). Backend-логика не дублируется в UI-слоях.

## Endpoints

Все endpoints под `/api/v1/media/*`, требуют tenant context + user authentication. Authorization через политики (видит только свой tenant).

### Folders

```
GET    /api/v1/media/folders                          # tree (root + всё дерево, для tree-навигации)
GET    /api/v1/media/folders/{id}                     # детали папки + breadcrumbs
POST   /api/v1/media/folders                          # create { parent_id?, name }
PATCH  /api/v1/media/folders/{id}                     # rename / move { name?, parent_id? }
DELETE /api/v1/media/folders/{id}                     # delete (если пустая) или recursive (с флагом)
```

### Files

```
GET    /api/v1/media/files                            # query: folder_id?, kind?, search?, page, per_page
GET    /api/v1/media/files/{id}                       # детали файла + references count
POST   /api/v1/media/files                            # multipart upload { file, folder_id?, name? }
PATCH  /api/v1/media/files/{id}                       # rename / move { name?, folder_id? }
DELETE /api/v1/media/files/{id}                       # soft-delete
POST   /api/v1/media/files/{id}/restore               # restore from soft-delete
DELETE /api/v1/media/files/{id}/force                 # hard-delete (требует references = 0 или ?force=true)
```

### References

```
GET    /api/v1/media/files/{id}/references            # список references со snapshot (flow name + node info)
```

### Picker-specific (optimized для Vue picker)

```
GET    /api/v1/media/picker/contents?folder_id=...&kind=image
  # Возвращает за один запрос: subfolders + filtered files в текущей папке.
  # Папки видны всегда (даже если пустые по kind), файлы фильтруются по kind.
  # Используется в Vue picker для отрисовки одного уровня дерева.
```

## Request/Response shapes

### Upload

```
POST /api/v1/media/files
Content-Type: multipart/form-data

file: <binary>
folder_id: "01HX..." (optional)
name: "Custom name.pdf" (optional, defaults to original filename)

→ 201 { id, name, kind, size, mime_type, folder_id, created_at, deduplicated: bool }
```

### Picker contents

```
GET /api/v1/media/picker/contents?folder_id=01HX...&kind=image

→ 200 {
  current_folder: { id, name, breadcrumbs: [{id, name}, ...] } | null (root),
  subfolders: [{ id, name, file_count_total, file_count_filtered }, ...],
  files: [{ id, name, kind, size, mime_type, thumbnail_url?, preview_url? }, ...]
}
```

## Validation

### File upload

- Max size: configurable per tenant (default 100MB). Превышение → 422 с указанием лимита.
- Mime type whitelist (configurable): images (jpeg, png, gif, webp), videos (mp4, mov, webm), audio (mp3, ogg), docs (pdf, doc, docx, xls, xlsx).
- Detection mime через `finfo`, не через extension.
- Если provider-specific лимиты превышены → response включает warning (не блокирует upload, но возвращает `provider_warnings`):
    
    ```
    { ..., provider_warnings: [{ channel_type: 'telegram', limit: '50MB for documents', actual: '120MB' }] }
    ```
    
    UI показывает warning при выборе файла в picker для send_message ноды.
    

### Folder operations

- Folder depth limit: 10 уровней. Move в более глубокий уровень → 422.
- Циклы: запрещено move папки в её собственный subtree → 422.
- Имя папки: 1–255 символов, без `/` и `\`.
- Дубликаты имени в одной parent папке: разрешены (UI обозначит счётчик), либо запрещены → решить при реализации. **Рекомендация: разрешить, как в обычных file managers.**

### Delete

- Hard delete с references > 0 → 409 Conflict с `references` в response. Force через `?force=true` для админа.

## Authorization

- Permission `media.view` — все endpoints чтения.
- Permission `media.manage` — create / update / delete.
- Все запросы scoped по `tenant_id` из TenantContext (нельзя получить media чужого тенанта даже зная id).

## What NOT to do

- Не возвращать `storage_path` или `storage_disk` в API response (внутренние детали).
- Не отдавать файлы напрямую через GET `/files/{id}/raw` — для preview/download использовать **signed URLs** через storage driver (S3 presigned, local через temporary signed routes).
- Не делать blocking image resize / thumbnail generation в request thread — async через job, поле `thumbnail_url` приходит после готовности (или null если не готово).
- Не дублировать логику authorization в каждом controller — middleware + policy.
- Не делать references inspection через JSONB scan на лету — только из `media_file_references` таблицы.
- Не позволять hard delete без явного флага если references > 0.

## Tests

### Feature

- Upload: success / mime mismatch / size exceeded / folder not found / duplicate hash → dedup hit.
- Folder CRUD: create / move / delete (empty + non-empty) / cycle prevention.
- File operations: rename / move / soft-delete / restore / hard-delete with references.
- Picker contents: фильтрация по kind, breadcrumbs корректны.
- Authorization: cross-tenant access denied.
- Provider warnings: загрузка большого файла → warning для соответствующего канала.

## Acceptance criteria

- Все endpoints отвечают согласно спецификации.
- Upload работает streaming (не загружает весь файл в RAM).
- Authorization scoped по tenant.
- Picker contents возвращает subfolders + filtered files за один запрос.
- Provider warnings корректно учитывают лимиты Telegram.
- Все feature тесты зелёные.

## Files

```
app/Http/Controllers/Api/Media/
  FoldersController.php
  FilesController.php
  PickerController.php
  ReferencesController.php

app/Http/Requests/Media/
  UploadFileRequest.php
  CreateFolderRequest.php
  UpdateFolderRequest.php
  UpdateFileRequest.php

app/Http/Resources/Media/
  MediaFileResource.php
  MediaFolderResource.php
  MediaFileReferenceResource.php

app/Policies/
  MediaFilePolicy.php
  MediaFolderPolicy.php

routes/api.php (media routes)
```