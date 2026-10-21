# Roadmap — Tenant media storage

Destination: an integrator moves an installation's media between disks with one command and no
downtime, and a SaaS tenant connects its own bucket from a list of providers, moves its files from
operator storage from the admin while it keeps working, and pays only for what the operator stores.

## Phase 1 — Moving storage is safe (self-hosted)

Goal: an installation changes its media disk without losing or hiding a file. Done when: an
installation with files switches from local to S3, runs `media:move --from=local --to=s3`, and a
file uploaded before, one uploaded during and one after the move open, download, go out through a
public link and are deleted.

- [ ] Blob-routed storage — every read, delete and recount uses the storage named by the blob's
      `storage_disk`; installation storages are named disks in config; the existing values `local`,
      `s3`, `tenant` keep their meaning; an unknown value is refused with the blob id logged
- [ ] Move command — `media:move` copies per blob, checks sha256, swaps with compare-and-set, is
      idempotent and resumable, deletes the source only on request; self-hosting docs replace the
      `mc mirror` runbook (after: Blob-routed storage — a move is only safe when each blob is read
      from where it is)

## Phase 2 — The client connects its own bucket (SaaS)

Goal: a tenant admin connects a bucket from the provider list and moves its files from the admin.
Done when: a trial tenant with files connects an AWS bucket, the move runs with progress while the
admin keeps uploading, every file opens throughout, and a MinIO on a private address is refused with
the reason shown.

- [ ] Storage targets — `media_storage_targets` in the tenant schema with encrypted, write-only
      credentials; the inline `media.storage` migrated into a target and deprecated (after:
      Blob-routed storage — targets are what `storage_disk` names)
- [ ] Guarded client — tenant storages built by one factory that resolves and pins every SDK request
      through the egress guard, enforced by a PHPat rule (after: Storage targets — it builds them)
- [ ] Provider presets — the first providers with fixed addressing, endpoint pattern and regions,
      each tested for multipart, ranged reads and delete (after: Guarded client — presets are built
      through it; waits on Q-2)
- [ ] Storage settings screen — tenant admin: choose operator storage or a provider, probe the
      connection, switch, retire; audit-log events (after: Provider presets — the screen offers them)
- [ ] Move from the admin — the move engine as a queued job per tenant, started from the settings
      screen with progress, pause and cancel, gated by `media_storage` when moving back (after: Move
      command — the same engine; after: Storage settings screen — where it starts)
- [ ] Own-storage entitlement — a Foundation contract Core asks before a tenant may connect; single
      mode answers yes; [saas] the plan answers (after: Storage settings screen — the screen is what it
      gates; waits on Q-1)

## Phase 3 — Counted and supported

Goal: limits, failures and deletion behave as the client was promised. Done when: moving a tenant to
its own bucket lowers its counted storage, a revoked key notifies admins once and shows on the
settings screen, and deleting the tenant leaves its bucket untouched.

- [ ] Storage limit by owner — `media_storage` counts only blobs on operator storage; client-storage
      bytes shown, not limited (after: Storage targets — the count groups by storage)
- [ ] Storage health — read and write failures per storage, retired ones included, shown on the
      settings screen and notified once per incident; dedup re-writes a missing object on a hash hit
      (after: Storage settings screen; waits on Q-4 for inbound media)
- [ ] Decommission with client storage — rows and operator-held objects removed, client buckets
      untouched, said in the deletion flow (after: Storage targets)
- [ ] fog: traffic meter — bandwidth through Core for tenants on their own bucket; waits on Q-3
- [ ] fog: direct downloads — presigned URLs to a client's bucket instead of streaming through Core

## Waves

1. Blob-routed storage
2. Move command; Storage targets
3. Guarded client; Decommission with client storage; Storage limit by owner
4. Provider presets
5. Storage settings screen
6. Move from the admin; Own-storage entitlement; Storage health

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
- A wave entry names an item by its title (the text before its first ` — `) or its task id,
  entries separated by `;` — `jig spec plan` reports an entry that names no item or several.
-->
