# Task 29 — Media Domain (parent)

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]

> Архив Notion. Актуальная документация: [[07-media-domain-asset-cache]]





Depends on: 13, 16, 17
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 6
Status: Готово
Task №: 29

## Goal

Добавить в Core домен управления медиафайлами: загрузка, хранение в per-tenant disk, отправка через каналы с lazy upload + cache, приём через input-ноду, user-managed папки, soft-delete с references tracking.

## Architecture reference

ADR-07 — Media Domain & Channel Asset Cache. Все ключевые решения зафиксированы там.

## Scope decomposition

Задача разбита на 5 подзадач, каждая testable и deployable независимо:

- **Task 29.1** — Backend core (миграции, модели, сервисы, контракты в foundation, per-tenant disk). Без UI и каналов.
- **Task 29.2** — Telegram media adapter + send_message handler rework. Включает правку существующего handler-а.
- **Task 29.3** — Media REST API (используется Filament-ом и Vue picker-ом).
- **Task 29.4a** — Filament MediaResource (пункт меню "Медиа" в админке тенанта).
- **Task 29.4b** — Vue media picker (компонент для node config panel в Task 17 builder).

## Dependency graph

```
29.1 (backend core)
  ├── 29.2 (Telegram adapter + handler rework)
  ├── 29.3 (REST API)
  │     ├── 29.4a (Filament resource)
  │     └── 29.4b (Vue picker → integrates into Task 17)
```

## Key invariants (apply to all subtasks)

- **Lazy upload**: файл грузится в канал только при первой отправке. Никакого pre-upload во все каналы.
- **Blob/file separation**: `media_blobs` (физический файл, дедуплицирован по SHA-256) vs `media_files` (пользовательская сущность с именем и папкой).
- **JSON ноды хранят `media_file_id`**, не `blob_id`. Внутри сервисов резолвится `media_file → blob → channel_ref`.
- **User-managed folders в БД**, не в FS. Storage path фиксирован как `tenants/{tenant_id}/media/{ulid}.{ext}`.
- **Per-tenant disk** через `Storage::build()` — не регистрировать глобально.
- **Channel cache привязан к blob_id**, не к media_file_id (duplicate files переиспользуют один upload).
- **Incremental references tracking**: при сохранении flow_definition парсим nodes, пишем `media_file_references` синхронно. Soft-delete media_file → SELECT, не JSONB scan.

## What this task does NOT do

- Не реализует WhatsApp media adapter (отдельная задача в Phase 4+).
- Не реализует quota enforcement (отдельная задача в Phase 5 SaaS).
- Не интегрируется с broadcast media (broadcast — будущая задача).
- Не делает image resize / video transcoding / thumbnail generation (отдельный модуль в будущем).

## Acceptance criteria (parent-level)

- Все 5 подзадач закрыты.
- Tenant может загрузить файл через Filament "Медиа".
- Tenant может выбрать файл в Vue picker внутри билдера.
- Send_message handler с image/document отправляет файл через Telegram, второй раз — без re-upload.
- Input-нода с file типом сохраняет файл в media domain.
- Soft-delete файла с активными references — UI показывает modal со списком.