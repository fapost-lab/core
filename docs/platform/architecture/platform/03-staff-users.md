# 03 — Staff Users

## Description

**Staff** are the people who log in to the admin panels. Not to be confused with **Contact** (end users who talk to an
assistant through a messenger).

Staff accounts are tenant-scoped: the table lives in the tenant schema, so it has no `tenant_id` column (isolation is
the schema itself).

---

## Schema

Tables live in the tenant schema (`database/migrations/tenant/`). Roles and permissions use the `spatie/laravel-permission`
tables (`HasRoles` on the `User` model); the platform adds columns to them.

| Table | Notes |
| --- | --- |
| `users` (migration `create_staff_users_table`) | `name`, `email`, `phone`, `password` (nullable until activation), `status` (`pending` / `active` / `suspended`, `UserStatus`), `is_active` |
| `roles` | Spatie table plus `display_name`, `priority`, `is_system` |
| `permissions` | Spatie table; names come from the `Permission` enum (`manage_*`, `view_*`, `reply_conversations`) |
| `model_has_roles`, `role_has_permissions`, `model_has_permissions` | Spatie pivots |
| `user_assistants` | Which assistants a staff user can open in the assistant panel |
| `user_activation_tokens` | Invitation flow: a pending user receives an activation mail and sets a password |

---

## System roles

| Role | Priority | Scope |
| --- | --- | --- |
| `admin` | 100 | Every permission |
| `content_manager` | 50 | Assistants, channels, flows (including publish), flow groups, translations, broadcasts, RAG, media, contacts (view only), conversations (`ViewConversations` and `ReplyConversations`), analytics |
| `analyst` | 30 | Read-only: analytics, flow sessions, contacts |

---

## Role source of truth

`RoleEnum` is the single source of truth for system roles: name, priority and permission set.
`RoleSeeder` iterates `RoleEnum::cases()`, creates every `Permission` case, then creates or syncs each system role
(`is_system = true`) and its permissions. It is idempotent, so adding a role means adding an enum case only.

The seeder is reached through `TenantAclSeeder`, `AclBootstrapService` (a thin proxy used by tenant provisioning) and the
`ops:tenants-seed-acl` command for existing tenants.

A role priority hierarchy applies (`UserPolicy`): a user cannot assign a role whose priority is greater than or equal
to their own maximum, and cannot change their own roles at all. Custom roles created in the UI must have a priority
strictly below the creator's. System roles cannot be deleted.

---

## Deactivation (`is_active`)

- `is_active = false` ends the user's sessions at once: `UserService::deactivate()` clears the remember token and deletes
  the user's rows from `sessions`.
- The `EnsureUserIsActive` middleware runs on every authenticated panel request: a deactivated user is logged out and gets
  401 even with a live session.
- A user cannot deactivate themselves, and the last active admin cannot be deactivated.
- Only an admin holding `manage_users` can deactivate or reactivate; a reactivated user must log in again.

---

## Related

- [04-assistant-domain](04-assistant-domain.md) — assistants that staff are attached to
- `notify` node — staff are recipients of `notify` (`StaffRecipientResolver`)
