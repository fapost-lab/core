---
id: rule-staff
type: rule
status: active
summary: Last-admin protection, admin Gate::before bypass, hashed activation tokens, at-most-once notify
domains:
  - staff
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Staff/**"
  - app/Providers/StaffServiceProvider.php
  - "app/Filament/Resources/Users/**"
  - "app/Filament/Resources/Roles/**"
  - database/seeders/RoleSeeder.php
  - "tests/*/Domains/Staff/**"
reviewed_at: 2026-10-05
---
# Staff rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **You cannot deactivate yourself or the last active admin** (`UserService`).
- **Deactivating a user deletes their sessions** (`UserService`).
- **Activation tokens are stored hashed, expire after 72 hours, and can be resent at most once
  every 5 minutes** (`ActivationTokenService`, `ResendActivationService`).
- **Admins bypass every policy** through `Gate::before` in `StaffServiceProvider`. A policy that
  returns `false` does not stop an admin — write policies with that in mind.
- **Staff notifications are delivered at most once:** the idempotency guard is set before
  delivery (`SendStaffNotificationJob`).

## Rules

- **Check authorization through policies against the `Permission` enum.** This is the pattern in
  every domain. Review only.
  The enum has 23 cases in six groups (assistants, flow/content, users, contacts, conversations,
  analytics/system), the deprecated `ManageFlow` included; the policies are `AssistantPolicy`, `ChannelPolicy`, `ContactPolicy`,
  `ContactGroupPolicy`, `ConversationPolicy`, the Flow policies (`FlowDraftPolicy`,
  `FlowGroupPolicy`, `FlowLogPolicy`, `FlowSessionPolicy`), `MediaFilePolicy`, `MediaFolderPolicy`,
  `RolePolicy` and `UserPolicy`.
- **The sensitive permission set lives in `Permission::isSensitive()`** (`RotateChannelToken`,
  `PublishFlow`, `ManageRoles`, `ViewConversations`, `ReplyConversations`); the Roles UI badges
  those. A new permission with elevated risk is added to that `match`, not flagged elsewhere.
- **`Permission::ManageFlow` is `@deprecated`** and kept for backward compatibility; use the granular
  flow permissions (`ManageFlowDefinitions`, `PublishFlow`, `ViewFlowSessions`, `ManageFlowGroups`,
  `ManageTranslations`) for new checks.
- **Role priority decides who may assign which role** (`CreatePendingUserService`). New role
  flows must use it.
- **A change to a system role's permissions in `RoleEnum` needs a tenant migration.** `RoleSeeder`
  runs only at provisioning and through the manual `ops:tenants-seed-acl`, never on deploy, so
  existing tenants keep the old set. The migration hard-codes the `web` guard (migrations may not
  read config) and leaves custom roles alone; see
  `2026_09_22_000001_grant_manage_channels_to_content_manager.php`. Review only.
