---
id: rule-conversation
type: rule
status: active
summary: Dedup per idempotency key, logger never throws, staff ownership blocks flows, no FK on messages
domains:
  - conversation
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Conversation/**"
  - config/conversation.php
  - "database/migrations/tenant/*conversation*"
  - "tests/*/Domains/Conversation/**"
reviewed_at: 2026-10-17
---
# Conversation rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **A transcript message is stored once** per `(conversation_id, direction, idempotency_key)`: an
  explicit check plus a unique index that includes `created_at`, the partition key. Enforced:
  `PostgresConversationStore`, `PostgresConversationStoreTest`.
- **Thread creation is race-safe** (`createOrFirst`).
- **The logger never throws to its caller.** Why: a transcript failure must not break message
  delivery or flow execution. Enforced: `ConversationLogger`.
- **A staff-owned thread stops inbound routing before flow execution** (`MessageRouter`).
- **An operator reply requires staff ownership, checked on the server;** returning a thread to
  the bot clears the operator (`ConversationOwnership`).

## Rules

- **Write to the transcript only through `ConversationLoggerInterface` with a capture.** Never
  insert into `conversation_messages` directly. Review only. *(proposed)*
- **Keep storage-specific code behind `ConversationStoreInterface`.** New read features should
  not add direct `ConversationMessage` queries; `ConversationInbox::transcript` (the console inbox) is the existing
  exception. Review only. *(proposed)*
- **`conversation_messages` has no foreign key** (the table is partitioned), so deleting a
  contact or thread leaves its messages behind. Deletion and retention work must remove them
  explicitly.
- **Retention drops whole monthly partitions of the current tenant schema only.** Never row
  `DELETE`s; threads (`conversations`) and media are not touched; the partition listing is
  filtered to `current_schema()` because every tenant schema has identically named partitions.
  Retention is one value for the whole installation: the platform setting `conversation.retention_days`
  (`PartitionRetention::SETTING`, 0–3650, 0 = keep forever), read by `conversations:prune` at run time through
  `PlatformSettingsReaderInterface`; `CONVERSATION_RETENTION_DAYS` is only its default. Why not per tenant:
  dropping is irreversible and storage volume is the operator's concern; a per-tenant value capped by the
  platform would be a new feature, and plan limits belong to the operator package. Enforced:
  `PartitionRetentionTest`, `PruneConversationMessagesCommandTest`, `PruneConversationRetentionSettingTest`,
  `ConversationPartitionManagerPgsqlTest` (real PostgreSQL, group `pgsql`).
- **Whether a tenant keeps a transcript and downloads inbound media is that tenant's setting, read at run
  time through `ConversationSettings`.** `TenantSettings::conversation_logging` / `conversation_media_fetch`
  are nullable: null (what the settings migration stores) means the install default from
  `CONVERSATION_LOGGING` / `CONVERSATION_MEDIA_FETCH`, so an upgrade changes nothing until a tenant admin saves
  Tenant settings. `ConversationSettings` is scoped, memoized per tenant id and reads a fresh `TenantSettings`
  (a tenant switch hides the scoped one). The logger skips the dispatch only when the context's tenant is the
  entry's; `PersistConversationMessageJob` decides inside `runForTenant()`, which is the authoritative check.
  Known edge: with the log off, a new inbound message on a thread an operator still holds is dropped by
  `MessageRouter` (`staff_handled`) and not written, so nobody sees it; the screen tells the admin to return
  held threads to the bot first. Enforced: `ConversationSettingsTest` (two tenants on pgsql),
  `ConversationLoggerTest`, `PersistConversationMessageJobSettingsTest`, `AdminTenantSettingsConsoleTest`.
