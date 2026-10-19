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
  - routes/media.php
  - config/media.php
  - "database/migrations/tenant/*media*"
  - "tests/*/Domains/Media/**"
  - "app/Http/Controllers/Admin/Media*Controller.php"
  - "app/Http/Requests/Admin/*Media*"
  - "resources/js/pages/Console/Media/**"
  - routes/media-public.php
reviewed_at: 2026-10-16
---
# Media rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **One blob per `(tenant, sha256)`:** a unique constraint plus a race-safe create-or-find; the
  loser of a race deletes its copy. Enforced: `EloquentMediaBlobRepository`, `MediaUploaderTest`.
- **An expired or missing channel ref triggers a re-upload; a soft-deleted file cannot be
  dispatched.** Enforced: `MediaDispatcherTest`.
- **The storage disk is built per call: the tenant's `media.storage` when it names a driver,
  otherwise the installation's `config('media.storage')` (`MEDIA_STORAGE_DRIVER`, `MEDIA_S3_*`)** —
  local by default, s3 (endpoint and path-style for MinIO) optional, anything else throws; a tenant
  override is never merged with the installation's, so it cannot borrow its credentials
  (`TenantMediaDisk`, `TenantMediaDiskTest`). Every read uses the disk resolved now, not the blob's
  `storage_disk` (which records the installation driver, `local` or `s3`, or `tenant` for the tenant's
own storage — so a later per-blob router and a move command can tell where a blob was written): switching the
  installation disk does not move files, so a populated installation copies `tenants/` first
  (self-hosting docs). Why not per-blob routing yet: owner decision 2026-10-19, demo first; per-tenant
  storage and blob-routed reads are spec `tenant-own-storage`.
- **Hard upload limits are in `UploadFileRequest`** (size and MIME allowlist); per-channel limits
  are advisory warnings only (`ChannelLimitInspector`).
- **A referenced file is protected:** force-delete requires `force=true`, and the cleanup job
  skips referenced files.
- **Policies:** reading needs `ViewMedia` or `ManageMedia`; writing needs `ManageMedia`.
- **A blob is created only by `EloquentMediaBlobRepository` and a file only by `MediaUploader`, and the
  uploader asks `MediaStorageGate` before it writes a byte for new content.** Order: stream into a local
  buffer, hash and measure, look the hash up (a known blob writes nothing), gate, then write. A refusal
  (`StorageLimitReachedException`) leaves no blob, no file and nothing on the tenant's disk. Callers word the
  refusal for their surface: REST 422, admin screen error toast (one file per request, the last answer
  speaks for the batch: "saved N of M"), transcript descriptor `failed` with
  `reason: storage_limit_reached`, input node `invalid` exit with `error_key: storage_limit_reached`.
  Enforced: `MediaStorageGateTest`, PHPat and unit `CountableModelCreationTest`.
- **Blob size is the byte count of the buffer (`ftell`), never `mb_strlen`/`strlen` of a chunk.** Pint's
  `mb_str_functions` fixer rewrites `strlen` to `mb_strlen`, which counts characters. Enforced:
  `MediaUploaderTest`. Blobs written before the fix are corrected by `media:recount-blob-sizes`.
- **Force delete reaps an unreferenced blob.** `MediaService::forceDelete()` deletes the file, then the blob row
  and the stored object when no file, trashed ones included, still uses it; the cleanup job, the REST
  `files/{id}/force` and the admin screen's permanent delete (`MediaLibraryService::forceDelete`) all go through it. The row goes first so the
  restrict foreign key protects a blob a parallel upload just reused. Only that violation is swallowed (SQLSTATE 23503, or SQLite's
  generic text); other database errors propagate. If deleting the object then fails, the delete still succeeds:
  a `media.blob.object_delete_failed` warning logs the path and the orphaned object stays for an operator,
  because the file is already gone and a 500 would not bring it back. Without this the storage limit would
  never get space back. Enforced: `MediaApiTest`, `MediaResourceTest`, `CleanupSoftDeletedMediaJobTest`.

- **A public media link opens one file of one tenant, briefly, and only while the file exists.** A channel
  that sends media by URL (Foundation `ChannelMediaLinkPolicyInterface`, tag `media.channel.link`) gets a link
  that `PublicMediaLinkIssuer` mints in the dispatcher just before delivery; nothing else mints one
  (`PublicMediaLinkSigner::sign()` is called only there; the signer is used only by the issuer and
  `PublicMediaLinkResolver`). The signature is HMAC-SHA256 under a key derived from `APP_KEY` with HKDF info
  `fapost.media.public-link.v1`, over the tenant id, file id, blob id, expiry and the last path segment, so a
  link of tenant A refuses on B's host and after the file changes blob. The resolver looks the file up only in
  the request's tenant and never in the trash, so a soft-deleted file refuses, and a stored object that is gone
  refuses too. Expiry is checked
  against `media.public_links.max_ttl_seconds` too (plus 60 s of clock skew), so a mis-minted link cannot live
  for ever. Previous keys (`app.previous_keys`) still verify; a malformed one is reported and skipped. Every
  refusal is the same 404 and logs `media.public_link.refused` with file, tenant and reason, never the URL.
  The route (`routes/media-public.php`) is outside the `web` group (no session, no cookie), throttled per link,
  and serves as an attachment under `sandbox` CSP and `nosniff`; a type outside `media.allowed_mime_types` goes
  out as `application/octet-stream`. Why: the link opens a tenant's file with no login, so a mistake leaks
  silently to people who never asked for it. Enforced: `PublicMediaLinkTest` (two tenants, host mode),
  `PublicMediaLinkSignerTest`, PHPat `PublicMediaLinkSignerTest`, `PublicMediaLinkSigningTest`.
- **A channel type with an uploader uploads; one with a link policy gets a link; one with neither fails.** The
  uploader wins when both exist, so Telegram never changes path; a link writes nothing to `media_channel_refs`;
  neither throws `ChannelMediaUploaderNotRegisteredException`. Enforced: `MediaDispatcherTest`.

## Rules

- **Send media to a channel only through `MediaDispatcherInterface`, and honour
  `alreadyDelivered`.** Why: on Telegram the upload is the send; sending again duplicates the
  message. Review only.
- **Filter media queries by `tenant_id` as well as relying on the schema switch** — the existing
  queries do both. `media_channel_refs` and `media_file_references` have no `tenant_id`.
  Review only. *(proposed)*
- **Folder rules live in `MediaFolderService`:** a folder is found inside the current tenant only,
  depth at most `media.folder.max_depth`, no move into the folder's own subtree, and deleting a
  folder first moves its files (trashed ones too, in one UPDATE) and direct subfolders (through
  `moveFolder`, so `path_cache` follows) into a target or the root. Each entry point decides when to
  ask: REST `DELETE /media/folders/{id}` answers 409 for a non-empty folder without `force=true`
  and then moves the contents to the root; the admin screen asks where the contents go. A refusal
  is `MediaFolderRuleException`, a 422 on the named field. Enforced: `MediaApiTest`,
  `AdminMediaConsoleTest`.
