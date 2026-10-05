# 04 — Assistant Domain (Assistants + Channels)

> Recorded March 2026; corrected against the code in October 2026. Replaces the old "Bots" page and the "Bot Domain —
> Terminology Reference" document. `Bot` is no longer a term in the platform.

---

## Core principle

`Assistant` is the top business aggregate of a tenant.

`Channel` is a transport endpoint owned by an `Assistant`.

`Flow` is the processing logic, owned by an `Assistant`.

`Session` is a runtime execution, started in the context of an `Assistant`.

---

## Domain structure

```
Assistant
 ├── Channels
 ├── Flows
 ├── Sessions
 ├── Context
 └── Logs
```

---

## Assistant

`Assistant` is a self-contained managed unit inside a tenant. A tenant can have several (Sales, Support, Warehouse, ...).
Tables live in the tenant schema; ids are ULIDs stored in `uuid` columns.

| Field | Description |
| --- | --- |
| `id` | ULID |
| `tenant_id` | Owning tenant |
| `name` | Display name |
| `is_active` | Activity flag |
| `default_flow_id` | Default flow (nullable) |
| `default_language` | Assistant default language (see the multilingual ADR) |
| `fallback_message` | Localized message map (`array<locale, string>`) sent on error |
| `busy_message` | Localized message map sent when the session lock cannot be acquired |
| `commands` | JSON list of bot commands (name, description, behavior), edited in assistant settings |
| `available_countries` | ISO alpha-2 list of countries offered by phone-input nodes and the builder (`CountryCatalog`) |
| `settings` | JSON: runtime settings |

---

## Channel

`Channel` is owned by an `Assistant` and handles **only** transport and connection. It is not an independent business
entity.

| Field | Description |
| --- | --- |
| `id` | ULID |
| `assistant_id` | FK to `assistants` (restrict on delete) |
| `tenant_id` | Owning tenant |
| `type` | `ChannelTypeEnum`: `telegram`, `whatsapp` (WhatsApp has no production adapter yet) |
| `token` | Encrypted |
| `secret_token` | Encrypted |
| `telegram_bot_username` | Bot username resolved from Telegram (nullable) |
| `webhook_public_hash` | Unique, opaque |
| `config` | JSON: channel-specific |
| `is_active` | Activity flag |

`ChannelObserver` is the single owner of channel lifecycle side effects: Redis registry sync and provider webhook
registration, both deferred until the transaction commits.

---

## Runtime rule

Every incoming message reaches a `Channel` first:

```
POST /webhook/{channel}/{hash}
  → resolve registry entry by hash (Redis, landlord DB fallback)
  → entry carries tenant_id, assistant_id, channel_id, schema
  → run the assistant flow
```

A flow is started by the **assistant**, not by the channel. One `Assistant` has many `Flow`s; a `Channel` uses the flows
of its `Assistant`.

| Entity | Owner |
| --- | --- |
| `Flow` | `Assistant` |
| `Session` | `Assistant` |
| `Channel` | `Assistant` |

---

## Redis webhook registry

**Key:** `webhook:{public_hash}` (the application Redis prefix is applied by the framework).

**Value:**

```json
{
  "tenant_id": "...",
  "assistant_id": "...",
  "channel_id": "...",
  "schema": "tenant schema name",
  "channel": "telegram",
  "secret_token": "plain secret"
}
```

The database is the source of truth: the landlord `webhook_registry` table. Redis is a write-through cache with no TTL;
a miss falls back to the landlord table and self-heals, guarded by a short `warming:{hash}` leader lock.

---

## Distributed lock key

```
session_lock:{tenant_id}:{contact_id}:{assistant_id}
```

Isolation is per assistant, not per channel (`LockScope`).

---

## UI architecture

Two Filament panels, both served on the tenant host.

- **Admin panel** (`/admin`): top-level tenant management. Resources: Assistants (with a Channels relation manager),
  Users, Roles, Media. Pages: tenant settings, translations, dashboard.
- **Assistant panel** (`/assistant/{assistant}`): Filament tenancy, where the Filament tenant is the `Assistant`
  (`CurrentAssistantInterface` resolves it). Resources: Flows, Flow groups, Flow sessions, Flow logs, Contacts, Contact
  groups, Contact segments, Conversations, Broadcasts, Channels. Pages: dashboard, assistant settings (general, commands,
  advanced), translations.

The tenant switcher in the assistant panel lists only the assistants the user may view.

---

## Triggers and broadcasts

Broadcasts belong to an assistant (`broadcasts.assistant_id`, managed in the assistant panel). Flow triggers carry a
nullable `assistant_id`: a null value is a tenant-wide trigger, and the FK is `nullOnDelete` so such triggers survive
assistant removal.

---

## Access control

User ↔ Assistants is many-to-many (`user_assistants`). `AssistantPolicy`: managing assistants requires `manage_assistants`,
and a non-admin additionally needs the assistant assigned to them. This affects the assistant list, the assistant panel
tenant list, and the assistant pickers.

---

## Terms

| Layer | Term |
| --- | --- |
| Business entity | `Assistant` |
| Transport | `Channel` |
| Logic | `Flow` |
| Runtime state | `Session` |
| Tables | `assistants`, `channels` |
| UI | "Assistants", "Channels" |

---

## Related

- [05-contacts](05-contacts.md) — contacts and their channel identities
- [10-message-pipeline](10-message-pipeline.md) — message pipeline through the assistant
- [03-staff-users](03-staff-users.md) — staff users and assistant access
- ADR 15 (multilingual) — `default_language`
