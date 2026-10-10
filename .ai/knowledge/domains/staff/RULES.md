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
  - app/Http/Controllers/Admin/UserController.php
  - app/Http/Controllers/Admin/RoleController.php
  - "app/Http/Requests/Admin/**"
reviewed_at: 2026-10-10
---
# Staff rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **You cannot deactivate or delete yourself or the last active admin** (`UserService`,
  `StaffUserService`). The last-admin check runs inside the transaction that removes the admin and
  locks the active admins' rows (`UserService::isLastActiveAdmin()`), so two admins removing each
  other at once cannot both pass. Only accepted accounts count (status Active and `is_active`): a
  pending admin invitation does not keep the tenant reachable. Enforced: `AdminUsersConsoleTest`.
- **Nobody changes their own roles, admins included** (`StaffUserService`; the console form shows
  one's own roles read-only, and sending them back unchanged is no change). Enforced:
  `AdminUsersConsoleTest`.
- **Roles are given and taken within the actor's reach.** An admin gives and takes any staff role,
  the admin role included (owner's decision, 2026-10-10; Filament never offered it), but never from
  themselves and never from the last active admin. Everyone else gives and takes only roles below
  their highest priority, and only on users they outrank (`UserPolicy::updateRoles()`,
  `StaffUserService::assignableRoles()`). Enforced: `AdminUsersConsoleTest`.
- **Someone else's profile and account need a higher priority:** `update` plus `updateProfile` to
  edit name, email, phone or password, and `delete` requires outranking the target as well
  (`UserPolicy`; admins pass through `Gate::before`). Enforced: `AdminUsersConsoleTest`.
- **Deactivating a user deletes their sessions** (`UserService`).
- **Activation tokens are stored hashed, expire after 72 hours, and can be resent at most once
  every 5 minutes** (`ActivationTokenService`, `ResendActivationService`).
- **Admins bypass every policy** through `Gate::before` in `StaffServiceProvider`. A policy that
  returns `false` does not stop an admin — write policies with that in mind.
- **Nobody changes or removes the platform support user, admins included.** The Gate refuses
  update, delete, deactivate, activate and role changes on `is_platform_support` users in
  `Gate::before`, ahead of the admin bypass (`PlatformSupportProtection`); `UserService` refuses
  them too; the users table bulk delete authorizes each record. The support user has a null
  password, is not counted as the last active admin, and is left out of the staff limit count (`User::scopeCountedForLimit()`); it is created by `PlatformSupportUserService`, allowed in the record-creation architecture rules, and never refused by the limit.
  Enforced: `PlatformSupportProtectionTest`.
- **A support session ends 60 minutes after it began** (`EndExpiredSupportSession`, in the `web`
  group and both panels) and every entry is recorded in `support_access_entries`, closed by a
  `Logout` listener. Enforced: `SupportAccessEntryTest`.
- **Staff notifications are delivered at most once:** the idempotency guard is set before
  delivery (`SendStaffNotificationJob`).

- **Who may use the console is decided in one place, `User::canAccessConsole()`** (status Active
  and `is_active`); Filament's `canAccessPanel()` delegates to it, the Inertia sign-in and
  `EnsureCanAccessPanel` call it, so the two stacks cannot disagree. Enforced: `ConsoleLoginTest`,
  `ConsoleStackTest`.
- **The Inertia sign-in behaves like Filament's** (`ConsoleLoginService`): 5 attempts a minute per
  IP, every attempt counted; one failure message for an unknown email, a wrong password and an
  account that may not sign in, padded by a `Timebox`; the `Attempting`, `Failed` and `Login` events
  (the SaaS shell listens to `Login`). Enforced: `ConsoleLoginTest`.

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
