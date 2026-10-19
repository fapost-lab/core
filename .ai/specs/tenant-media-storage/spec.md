# Tenant media storage — operator storage, the client's own bucket, and moving between them

Depth: normal — the owner set the directions (2026-10-19); commercial and product decisions that are
still open are listed under "Open questions".

## The idea, as said

"We have several directions. For the SaaS we can provide our own storage with limits and sell it. Or
let the client connect their own storage — Amazon or MinIO, it does not matter — we can offer
providers and let them choose. This is done once, when the tenant is set up. And it needs a migration
mechanism right from the admin. For self-hosted we just put in `.env` which storage works and its
credentials, plus a mechanism to move from where to where. That is on the integrator."
(owner, 2026-10-19)

## Understood

- One sentence: every tenant's media lives on exactly one storage at a time — the operator's (SaaS,
  sold with limits), the client's own bucket from a list of providers (SaaS), or the installation's
  disk from `.env` (self-hosted) — and moving between storages is a supported operation with
  progress, not a copy someone runs by hand.
- Who is worse off without it: SaaS clients that must keep files in their own account or region;
  the operator, who pays for storage it cannot sell in tiers; integrators, who today have only a
  runbook (`mc mirror`) and a maintenance window to change the disk.
- Success: a SaaS tenant on the trial's operator storage connects its own bucket from the admin,
  starts the move, keeps working while it runs, and every file — older and newer — opens throughout;
  an integrator moves an installation from local to S3 with one command and no downtime.

## Starting point (Core, after `core-media-object-storage`)

- `TenantMediaDisk` resolves one disk per tenant: the tenant's inline `tenants.config.media.storage`
  when it names a driver, otherwise the installation disk from `.env` (`MEDIA_STORAGE_DRIVER`,
  `MEDIA_S3_*`; local by default; s3 with endpoint and path-style for MinIO).
- Every read, delete and recount uses the disk resolved **now**. `media_blobs.storage_disk` records
  where a blob was written — the installation driver (`local`, `s3`) or `tenant` — but nothing reads
  it yet.
- The inline tenant override keeps secrets in clear text in the landlord `tenants.config` JSON and
  has no admin screen; no tenant uses it.
- Object keys are `tenants/{id}/{kind}/{yyyy-mm}/{shard}/{blob}.{ext}` on every disk; downloads,
  public links and channel uploads stream through Core.
- `media_storage` is a registered record limit (bytes, each unique blob once, trash included);
  the SaaS package sells limits through plans and add-ons.

## Directions

1. **Operator storage, sold with limits (SaaS).** The default for every tenant, the trial included.
   Nothing new in Core beyond what exists: the `media_storage` limit, its refusals and notifications.
   More space is a plan or an add-on in the SaaS package.
2. **The client's own bucket (SaaS).** Chosen from a list of providers; connected once, usually when
   the tenant leaves the trial; files already on operator storage are moved from the admin.
3. **Installation disk (self-hosted).** `.env` names the storage and its credentials; moving between
   disks is a command the integrator runs. Core supplies the mechanism, the integrator owns the
   operation.

## Decisions

- **A blob is read from the storage it was written to.** `storage_disk` becomes an address. Writes
  go to the tenant's active storage; reads, deletes and recounts go to the blob's. Rejected: reading
  through the current setting — during any move files are on two storages, and a switch without a
  move would cut off every existing file.
- **Storages are named.** The installation's storages are named disks in config (`local`, `s3`, and
  more names when an integrator needs two S3 targets at once during a move); a tenant's own storage
  is a record in the tenant schema (`media_storage_targets`) whose id is what blobs record. The
  values already on blobs (`local`, `s3`, `tenant`) keep their meaning. An unknown value is refused
  with the blob id logged. A storage referenced by any blob is never deleted, only retired (no new
  writes).
- **One move engine, two entry points.** Per blob: copy, compare sha256 with the blob row, then
  `UPDATE storage_disk = :to WHERE id = :id AND storage_disk = :from`; if no row changed (deleted or
  moved meanwhile) the copy is removed. Idempotent and resumable; the source object is deleted only
  after the swap and only when asked. SaaS: started from the tenant admin, one queued job per tenant
  on a named media queue, chunked, with progress, pause and cancel. Self-hosted: `media:move` with
  `--from`, `--to`, `--tenant`, `--delete-source`, `--dry-run`, iterating tenants through the tenant
  switcher. Rejected: a stop-copy-switch cutover — it needs a maintenance window, and a half-done copy
  turns into 404s.
- **Switching storage is a move.** The admin's "connect a bucket" makes it the active storage for new
  files and offers to move the rest; there is no bare pointer change that leaves files behind
  unannounced. Moving back to operator storage is gated by the `media_storage` limit before each
  copy, so a tenant cannot bring back more than it paid for.
- **Providers are presets, not free input.** Each provider in the list (AWS S3, a self-hosted MinIO,
  and others the owner picks) fixes the addressing style, the endpoint pattern and the regions, and is
  tested against the real service: multipart uploads, ranged reads, delete. "Other S3-compatible" with
  a free endpoint is offered only if the owner wants it, marked unsupported. Rejected: four free fields
  for every client — the connection probe passes and large uploads fail on the first provider whose
  dialect differs.
- **Credentials are write-only.** Encrypted at rest with the application key (rotation through
  `app.previous_keys`), never returned to the browser, replaced as a whole; replacing them keeps the
  storage's identity. Every change is an audit-log event. The inline `tenants.config.media.storage`
  is migrated into a target record and removed after a deprecation release.
- **A client endpoint goes through the egress guard** that protects the `call` node: https, resolved
  and pinned on every request the S3 SDK makes (redirects and retries included), private, loopback,
  link-local and metadata ranges refused. Installation storages are exempt — the integrator or operator
  configured them, and MinIO usually sits on a private address. Tenant storages are built by one
  factory only, enforced by a PHPat rule.
- **Connecting checks the bucket first:** put, get (ranged), multipart put of a few MB, and delete of
  a probe under `tenants/{id}/.probe/`; the failure is shown in the admin's words (wrong key, bucket
  missing, no delete permission, unreachable).
- **Quota counts what the operator stores.** `media_storage` counts blobs on operator storages only;
  bytes on the client's bucket are shown, not limited. The per-file size limit applies everywhere.
- **Core never deletes objects in a client's bucket** on tenant decommission; it removes the rows and
  the objects on operator storage, and says so on the settings screen and in the deletion flow.
- **Whether a tenant may connect its own bucket is an entitlement** Core asks through a Foundation
  contract; single mode answers yes, the SaaS package answers from the plan. Losing it (a downgrade)
  stops new connections and offers a move back; it never stops reads of files already there.

## Stress test

Independent failure hunt (2026-10-19), ranked by likelihood × cost; the response, where one is
decided, follows each.

1. **The client deletes objects in its bucket** (lifecycle rule, cleanup, recreated bucket) while the
   blob row stays; dedup then finds the row and writes nothing for a re-upload of the same file.
   Response: dedup verifies the object exists on a hash hit when the blob is on a client storage, and
   writes it again if not; open question on a periodic existence check.
2. **Credentials of a retired storage go stale** after the client rotates its key; old files become
   unreadable weeks later. Response: a retired storage still shows its health on the settings screen,
   and a read failure on it notifies admins; moving everything off a storage before retiring it is the
   supported path.
3. **Provider dialects** — the probe passes, multipart or ranged reads fail. Response: presets and the
   multipart probe above.
4. **Inbound messenger media while the client's bucket is down** has nobody to show a refusal to, and
   the provider's link expires. Open question Q-4.
5. **A slow client bucket holds workers** — every transfer streams through Core, so a slow region
   backs up the media queue for every tenant, and channel fetches time out. Response: per-target
   timeouts and a per-tenant concurrency cap on media jobs; open question Q-3 on metering traffic.
6. **Dedup ignores storage** — a hash hit on operator storage leaves a file there for a tenant that
   moved to its own bucket. Response: a hash hit on a blob whose storage is not the tenant's active one
   is written to the active storage and the blob re-addressed (the move's swap), so residency holds.
7. **The application key is lost or rotated without `previous_keys`** — every client credential stops
   decrypting at once. Response: the self-hosting and operator docs name it; a decrypt failure is
   reported per storage, not as a generic 500.
8. **Moves at scale** — rate limits (SlowDown), quota running out halfway, a tenant split across
   storages. Response: backoff on 429/503, the quota gate per copy, and a move that stays readable at
   every step; "split" is a valid state with progress shown.
9. **The guard missing on one SDK path** (redirects, retries) — SSRF. Response: the single factory and
   the PHPat rule; a test that a redirect to a private address is refused.
10. **S3 clients cached in workers** across tenants. Response: built per call, never cached past a job
    (worker safety).
11. **A plan downgrade removes the entitlement** while files are in the client's bucket. Response:
    reads never depend on the entitlement.
12. **A schema restore brings back rows** whose objects in the client's bucket changed or vanished.
    Response: documented as the client's responsibility; the existence check of item 1 reports them.

## Rejected shapes

- A bucket per tenant created by the operator — provisioning would need storage admin rights.
- Mirroring operator storage into the client's bucket instead of storing there — does not keep data
  out of the operator's storage, which is why clients ask.
- Free S3 fields as the only way to connect — see "Providers are presets".

## Open questions

- Q-1 (owner, commercial): is connecting an own bucket a plan feature, a paid add-on, or free?
- Q-2 (owner): the provider list for the first release, and whether "other S3-compatible" is offered.
- Q-3 (owner): bytes on a client bucket still pass through Core on every download and channel send;
  is a traffic meter needed before own storage ships, or only once its cost is measured?
- Q-4 (owner, product): inbound media while the client's bucket is unreachable — keep it on operator
  storage for a bounded time and move it later (residency is broken for that window), or lose it and
  show "not saved: storage unavailable" in the transcript.
- Q-5 (owner, legal): what the terms say — no operator backups of a client bucket, no deletion on
  decommission, encrypted credentials.
- Q-6 (technical): whether the egress guard's resolve-and-pin client can serve as the AWS SDK's HTTP
  handler with streaming intact.
- Q-7 (technical): whether a dedup hash hit on a client storage checks existence every time or only
  when the object is older than some age (cost of a HEAD per duplicate upload).

## Not doing

- Storage per folder, per flow or per file type: one active storage per tenant.
- Non-S3 backends (SFTP, Azure Blob, Google Cloud Storage native API).
- Presigned direct downloads from a client's bucket (fog in the roadmap).
