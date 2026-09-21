---
id: glossary-media
type: glossary
status: active
summary: Blob vs file, folder, channel ref, reference, extractor, dispatcher, ingestor, source
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
---
# Media glossary

## Blob

The stored bytes, unique per tenant and SHA-256.

## File

The user-visible, soft-deletable entity that points at a blob. Several files may share a blob.

## Folder

A node in the database folder tree, with a `path_cache` breadcrumb.

## Channel ref

A cached provider file id for one blob on one channel, with an optional `expires_at`.

## Reference

A record that a flow definition's node points at a file.

## Extractor

The component that walks flow node JSON for `config.media_file_id`.

## Dispatcher

`MediaDispatcherInterface`: prepares a file for sending to a channel, uploading on first use.
Its `alreadyDelivered` result means the upload itself was the send (Telegram).

## Ingestor

`MediaIngestorInterface`: downloads a file from a provider, stores it through the upload
pipeline, and seeds the channel ref.

## Source

Where a file came from: `upload`, `input_node`, `api`, `conversation`.

## Inbox folder

The root folder that input nodes save received files into.
