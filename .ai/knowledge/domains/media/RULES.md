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
