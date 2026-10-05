# 05 — Contacts

## Description

**Contact** is a participant of a conversation, identified by the id the messenger gives them. A contact has no account
in the admin panels (that is a **Staff** user, see [03-staff-users](03-staff-users.md)).

A contact is tenant-scoped: it is unique per `(tenant_id, platform, external_id)`. It reaches an assistant through a
channel, and the link between a contact and each channel it has used is the `channel_contacts` row.

---

## Schema

All tables live in the tenant schema.

| Table | Description |
| --- | --- |
| `contacts` | `id`, `tenant_id`, `platform`, `external_id` (the messenger user id), `language`, `is_authenticated`, `meta` (JSON: name, username), `attributes` (JSON) |
| `channel_contacts` | contact ↔ channel link: `contact_id`, `channel_id`, `last_interaction_at`; unique per pair, cascade on delete |
| `contact_tags` | `contact_id`, `tag`, `tagged_by` (a flow session id or a staff user id, nullable), `tagged_at`; unique per `(contact, tag)` |
| `contact_groups` | Static named groups (`tenant_id`, `name`, `description`) |
| `contact_group_members` | Pivot: group ↔ contact |
| `contact_segments` | Dynamic segments: `name`, `rules` (JSON), cached count (`cached_count`, `cached_count_at`) |

Canonical columns, kept out of `attributes` on purpose:

- `language` — the contact's language, first stop of the language resolution chain (see ADR 15).
- `is_authenticated` — raised by the `auth_request` node once a contact passes a challenge. It sits in access-control hot
  paths, which is why it is a column.

---

## Important

> `contacts.attributes` is NOT a cache for module data. It holds only data collected by platform nodes (`input`,
> `assign`). Modules do not write there directly.

---

## Segmentation

Three ways to group contacts:

- **Groups** — static, hand-managed lists (`contact_groups`).
- **Tags** — set by the `set_tag` node or by staff (`contact_tags`).
- **Segments** — saved rules: tag has / not has, language / platform in / equals, combined with all / any.

`ContactSegmentResolver` compiles segment rules into a tenant-scoped contact query and resolves broadcast recipients
(`resolveContactIds`, `count`, `refreshCount`).

---

## Related

- [04-assistant-domain](04-assistant-domain.md) — contacts reach assistants through channels
- node specs: `input` (stores contact data), `assign` (updates contact attributes), `set_tag` (works with `contact_tags`)
- ADR 03 (id strategy), ADR 07 (media from contacts), ADR 15 (multilingual, `contacts.language`)
