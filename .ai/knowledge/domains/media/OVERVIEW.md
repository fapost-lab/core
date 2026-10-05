---
id: domain-media
type: domain
status: active
summary: Deduplicated tenant file storage, folders, per-channel upload cache, ingestion, flow references
domains:
  - media
topics: []
load: domain
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
# Media

## Responsibility

Media stores tenant files once per unique content, in the tenant's own storage. Staff organise
files in folders, which are database rows rather than filesystem paths. Media keeps a
per-channel cache of provider file ids, so a file is uploaded to a messenger once and then
reused. It also ingests files received from providers, renders previews, and tracks which flow
definitions reference which file, so a file in use is not deleted silently.

## Boundaries

- **The channel asset cache belongs to Media.** Its table (`media_channel_refs`, unique per blob
  and channel), model, repository and dispatcher all live here. Channels only contributes
  uploader and downloader adapters, through the container tags `media.channel.uploader` and
  `media.channel.downloader`.
- Depends on Flow's concrete `FlowDefinition` for reference tracking, through a
  `FlowDefinition::saved` hook registered in `Providers/MediaServiceProvider.php`. *(inferred:
  this dependency points the wrong way; Flow could report references to Media through a port)*
- Depends on the `Channel` model; Telegram's media adapters throw Media's exceptions, so Media
  and Channels import each other.
- Consumers: Flow (dispatcher, ingestor, media service — `InputNodeHandler`, `FlowMessageSender`,
  `ValidateFlowService`), Conversation (operator replies, media fetch), the assistant panel.

## Entry points

- Bindings: `Providers/MediaServiceProvider.php`. Uploader, dispatcher, ingestor and
  `MediaService` are `scoped`; registries and repositories are singletons.
- HTTP: `routes/media.php` (`auth`, `tenant`, `verified`; the signed raw-file route drops
  `auth`), controllers in `app/Http/Controllers/Media`.
- `app/Jobs/Media/CleanupSoftDeletedMediaJob.php` (queue `messaging.system`, daily).
- Admin UI: `app/Filament/Resources/Media`.
