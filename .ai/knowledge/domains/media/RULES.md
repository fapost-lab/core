---
id: rule-media
type: rule
status: active
summary: One blob per hash, re-upload on expired refs, protected referenced files, honour alreadyDelivered
domains:
  - media
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Media/**"
  - "app/Jobs/Media/**"
  - "app/Http/Controllers/Media/**"
  - "app/Http/Requests/Media/**"
  - "app/Http/Resources/Media/**"
  - "app/Filament/Resources/Media/**"
  - routes/media.php
  - config/media.php
  - "database/migrations/tenant/*media*"
  - "tests/*/Domains/Media/**"
reviewed_at: 2026-10-05
---
# Media rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **One blob per `(tenant, sha256)`:** a unique constraint plus a race-safe create-or-find; the
  loser of a race deletes its copy. Enforced: `EloquentMediaBlobRepository`, `MediaUploaderTest`.
- **An expired or missing channel ref triggers a re-upload; a soft-deleted file cannot be
  dispatched.** Enforced: `MediaDispatcherTest`.
- **The storage disk is built per call from the tenant's `media.storage` config** — local by
  default, s3 optional, anything else throws (`TenantMediaDisk`).
- **Hard upload limits are in `UploadFileRequest`** (size and MIME allowlist); per-channel limits
  are advisory warnings only (`ChannelLimitInspector`).
- **A referenced file is protected:** force-delete requires `force=true`, and the cleanup job
  skips referenced files.
- **Policies:** reading needs `ViewMedia` or `ManageMedia`; writing needs `ManageMedia`.
- **A blob is created only by `EloquentMediaBlobRepository` and a file only by `MediaUploader`, and the
  uploader asks `MediaStorageGate` before it writes a byte for new content.** Order: stream into a local
  buffer, hash and measure, look the hash up (a known blob writes nothing), gate, then write. A refusal
  (`StorageLimitReachedException`) leaves no blob, no file and nothing on the tenant's disk. Callers word the
  refusal for their surface: REST 422, Filament danger notification, transcript descriptor `failed` with
  `reason: storage_limit_reached`, input node `invalid` exit with `error_key: storage_limit_reached`.
  Enforced: `MediaStorageGateTest`, PHPat and unit `CountableModelCreationTest`.
- **Blob size is the byte count of the buffer (`ftell`), never `mb_strlen`/`strlen` of a chunk.** Pint's
  `mb_str_functions` fixer rewrites `strlen` to `mb_strlen`, which counts characters. Enforced:
  `MediaUploaderTest`. Blobs written before the fix are corrected by `media:recount-blob-sizes`.
- **Force delete reaps an unreferenced blob.** `MediaService::forceDelete()` deletes the file, then the blob row
  and the stored object when no file, trashed ones included, still uses it; the cleanup job, the REST
  `files/{id}/force` and the Filament `ForceDeleteAction` all go through it. The row goes first so the
  restrict foreign key protects a blob a parallel upload just reused. Only that violation is swallowed (SQLSTATE 23503, or SQLite's
  generic text); other database errors propagate. If deleting the object then fails, the delete still succeeds:
  a `media.blob.object_delete_failed` warning logs the path and the orphaned object stays for an operator,
  because the file is already gone and a 500 would not bring it back. Without this the storage limit would
  never get space back. Enforced: `MediaApiTest`, `MediaResourceTest`, `CleanupSoftDeletedMediaJobTest`.

## Rules

- **Send media to a channel only through `MediaDispatcherInterface`, and honour
  `alreadyDelivered`.** Why: on Telegram the upload is the send; sending again duplicates the
  message. Review only.
- **Filter media queries by `tenant_id` as well as relying on the schema switch** — the existing
  queries do both. `media_channel_refs` and `media_file_references` have no `tenant_id`.
  Review only. *(proposed)*
- **Folder rules live in `FoldersController`, not in the service:** depth at most 10, no move
  into the folder's own subtree, deleting a non-empty folder needs `force=true`. A new entry
  point must apply them, or move them into the service first. *(proposed)*
