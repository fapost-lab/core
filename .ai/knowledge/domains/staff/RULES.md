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
  - database/seeders/RoleSeeder.php
  - "tests/*/Domains/Staff/**"
  - app/Http/Controllers/Admin/UserController.php
  - app/Http/Controllers/Admin/RoleController.php
  - "app/Http/Requests/Admin/**"
reviewed_at: 2026-11-20
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
- **Activating by the link signs the invitee in on a session of their own.** Whoever was signed in in
  that browser is logged out and the session invalidated before the invitee is logged in
  (`ActivationController::store()`, as the support entry does): the session would otherwise keep the
  previous user's `password_hash_*`, which `AuthenticateSession` compares with the invitee's and signs
  them out on the next request, and their state (intended URL, support session). `ActivateUserService`
  only sets the password and status; signing in is the controller's. Enforced: `ActivationSingleModeTest`.
- **Admins bypass every policy** through the Staff `Gate::before` (`StaffGate`, registered by `StaffServiceProvider`). A policy that
  returns `false` does not stop an admin — write policies with that in mind.
- **Nobody changes or removes the platform support user, admins included.** The Gate refuses
  update, delete, deactivate, activate and role changes on `is_platform_support` users in
  `Gate::before`, ahead of the admin bypass (`PlatformSupportProtection`); `UserService` refuses
  them too; the users table bulk delete authorizes each record. The support user has a null
  password, is not counted as the last active admin, and is left out of the staff limit count (`User::scopeCountedForLimit()`); it is created by `PlatformSupportUserService`, allowed in the record-creation architecture rules, and never refused by the limit.
  Enforced: `PlatformSupportProtectionTest`.
- **A support session ends 60 minutes after it began** (`EndExpiredSupportSession`, in the `web`
  group) and every entry is recorded in `support_access_entries`, closed by a
  `Logout` listener. Enforced: `SupportAccessEntryTest`.
- **Staff notifications are delivered at most once:** the idempotency guard is set before
  delivery (`SendStaffNotificationJob`).
- **In-app staff notifications are stored in the shape Filament's database notifications wrote**
  (`notifications.data`: `title`, `body`, `status`, `icon`, `iconColor`, `duration`, `format: filament`
  and the rest, key for key), by Core's own `StaffMessageNotification`, so rows written before and after
  Filament left read the same way when an inbox shows them. A message without a link keeps `actions: []`; one with a
  link (`StaffMessage::$url`) stores it as the single entry `{name: open, label, url}` of `actions`. They are sent with `notifyNow` inside the
  notify job's `runForTenant`, never queued, for the reason limit notices are: a queued notification
  restores the `User` outside the tenant. Enforced: `StaffNotificationTest`.
- **A user reads and marks only their own notifications, admins included.** Ownership is the query, not a
  policy: `StaffNotificationInbox` goes through `$user->notifications()` every time, because `Gate::before` would
  let an admin through any policy. Another user's id, or one that is not a uuid, is a 404; the tenant boundary is the
  schema. The inbox returns plain text (markup stripped, never rendered) and keeps a link only when it is a path on
  this host or an `http(s)` address with no whitespace, control character or backslash anywhere.
  Enforced: `NotificationBellConsoleTest`.
- **Only the user it names listens on a staff user's notification channel** (`tenant.{tenant}.user.{user}.notifications`,
  `StaffNotificationsChannel`): the tenant of the request and the user's id are compared, never a policy, because
  `Gate::before` would let an admin onto anyone's channel. The announcement on it (`StaffNotificationsChanged`) carries
  no data, waits for the commit, is sent only while a broadcaster delivers, and a failure is reported, never thrown
  into whatever sent or read the notification. Enforced: `StaffNotificationsLiveTest`.
- **A link a staff notifier delivers is a path on the tenant's host or an `http(s)` address** (`StaffMessage`). In-app
  keeps the path as it is (the bell opens it on whichever host the user is on); email makes it absolute on the current
  tenant's host through a closure the provider supplies, read at the time of sending, because `StaffNotifierRegistry`
  is a singleton and must not capture a tenant. Review only.
- **A notification that stores what it is about is shown in the language of the page.** The stored prose is written
  in the recipient's `users.locale`, which is usually empty (the console's language lives in the session and the
  cookie), so it falls back to `app.locale`. A notification class that implements `LocalizedDatabaseNotification`
  renders its title, body and action label again from its stored keys, and `StaffNotificationInbox` calls it with the
  request's locale; the stored prose stays the fallback. Enforced:
  `OnboardingLateStepTest::test_the_bell_shows_a_late_step_in_the_language_of_the_page`.
- **Admins hear about a limit once per limit and episode, and a broken path never changes the
  gate.** `NotifyAdminsOfLimit` (synchronous, on the hot path) does one atomic `Cache::add` on the
  `LimitEpisode` key and queues `SendLimitNoticeJob` only for the first refusal of an episode:
  per-period = the operator's `periodEndsAt` or the UTC calendar month; records/bytes = the limit
  value, repeated at most every 7 days. Any error is reported, never thrown, and the cache key is
  released when queueing fails. The job (`messaging.system`, keeps running in a stopped tenant) runs
  inside `runForTenant` and sends with `notifyNow`, never a queued notification, whose
  `SerializesModels` would restore the `User` outside the tenant. Recipients are active admins with
  status Active, the platform support user excluded (`StaffRecipientResolver::admins()`); the
  recipient's language is `users.locale`: an explicit switch (`LocaleController`) always updates it, sign-in (`RememberUserLocale`) fills it only while empty.
  `NotifyAdminsOfLimit` also listens to `LimitApproaching` (a per-period limit the operator flagged as
  close): the warning has its own episode key (`ap{period end}` / `am{Y-m}`, against `p…` / `m…`), so it
  never silences the notice that the limit is used up in the same period; the same job and the same
  channels (database + mail, `kind` `limit_approaching`). `SendLimitNoticeJob::$reason` is a plain
  nullable property, not a promoted one: a job queued by an older release has none and falls back to the
  reason inferred from `refused`, and the field must never become required while such jobs may be in the
  queue. The operator is asked with `LimitNoticeReason::Approaching`.
  Enforced: `LimitNotificationTest`, `LimitEpisodeTest`, `RuntimeJobAccessModeTest`.
- **`LimitNoticeInterface` (Foundation) replaces only the "what to do next" block of the limit
  notification.** It may read the operator's tables and answer in the asked locale; it must not repeat
  the limit's name or numbers, send mail, dispatch jobs, call Core's classes or keep tenant data between
  calls. Core asks from the worker once per recipient locale, treats an exception as `null` and falls
  back to "contact the platform administrator" (`NoLimitNotice` is the `bindIf` default). Review only.

- **Who may use the console is decided in one place, `User::canAccessConsole()`** (status Active
  and `is_active`); the console's sign-in and `EnsureCanAccessPanel` call it, so the two cannot
  disagree. Enforced: `ConsoleLoginTest`,
  `ConsoleStackTest`.
- **The console's sign-in keeps the behaviour of the removed Filament login** (`ConsoleLoginService`): 5 attempts a minute per
  IP, every attempt counted; one failure message for an unknown email, a wrong password and an
  account that may not sign in, padded by a `Timebox`; the `Attempting`, `Failed` and `Login` events
  (the SaaS shell listens to `Login`). Enforced: `ConsoleLoginTest`.

## Rules

- **Check authorization through policies against the `Permission` enum.** This is the pattern in
  every domain; tenant API token surfaces are the exception (see the token rule below). Review only.
  The enum has 31 cases in eight groups (assistants, content, users, contacts, conversations,
  analytics, system, automations), the deprecated `ManageFlow` included; the policies are `AssistantPolicy`, `ChannelPolicy`, `ContactPolicy`,
  `ContactGroupPolicy`, `ConversationPolicy`, `CredentialPolicy`, the Flow policies (`FlowDraftPolicy`,
  `FlowGroupPolicy`, `FlowLogPolicy`, `FlowSessionPolicy`), `MediaFilePolicy`, `MediaFolderPolicy`,
  `RolePolicy` and `UserPolicy`.
- **The sensitive permission set lives in `Permission::isSensitive()`** (`RotateChannelToken`,
  `PublishFlow`, `ManageRoles`, `ViewConversations`, `ReplyConversations`, `ViewOperatorScreens`,
  `ViewActivityLog`, `ManageApiTokens`, and the whole automations group, `StartFlowRuns` included: `ManageAutomations`, `ManageCredentials`,
  `UseCredentials`, `ViewRunPayloads`); the Roles UI badges
  those. A new permission with elevated risk is added to that `match`, not flagged elsewhere.
- **A machine credential of a tenant is a tenant API token, one model for every surface.** The API
  trigger, MCP and any later machine surface use the tenant's `api_tokens` (Staff), the `api-token`
  guard and stack, and `Permission` values as abilities through `Gate` — never a token table or a
  scope vocabulary of their own. A token surface is the one exception to "check authorization
  through policies" above: it asks `Gate` by `Permission` value only (`Gate::allows(Permission::X->value)`)
  and never a policy ability such as `Gate::authorize('view', $model)`, which the token's fail-closed
  branch denies. Model: `adr-20261119-tenant-api-tokens` (proposed). Review only.
- **The Staff `Gate::before` runs ahead of every other before-callback** (`StaffGate`, registered from
  `StaffServiceProvider::register()` through `callAfterResolving(Gate)`): in order the support user's protection as a
  target, the support user refused `manage_api_tokens` and every ability on `ApiToken` as an actor, the `ApiToken`
  branch (held ability `true`, anything else `false`, never `null`; `Permission::isForbiddenToTokens()` always
  `false`; an expired or revoked token holds nothing), the operator screens (`null`), the admin bypass. It must stay
  ahead of the permission package's own before-callback, which answers `true` for any permission a role holds — the
  support user's admin role included. Enforced: `ApiTokenGateTest`, `PlatformSupportProtectionTest`.
- **A tenant API token is issued, rotated and revoked only by `ApiTokenService`.** Only the SHA-256 of the 40-character
  base62 secret is stored; the plain `fpt_<ULID>_<secret>` exists once, in the issue or rotate response (an Inertia
  flash). Abilities are `Permission` values the actor holds; never one `isForbiddenToTokens()`; for a non-admin never
  one `Permission::isNarrowedByRecordPolicy()` (every permission but the tenant-wide `ViewSystem`, `ManageSettings`,
  `ViewActivityLog`, `ManageCredentials` — `StartFlowRuns` included). Rotation takes a subset of the predecessor's
  abilities, refuses an expired or revoked token, cuts the predecessor to `min(expires_at, now + grace)` and marks it
  `expiry_notified_at`. Every token has an expiry within the `api_tokens.*` platform settings (90 by default, at most
  365). The support user is refused by the service too. Enforced: `ApiTokenLifecycleTest`, `ApiTokensConsoleTest`.
- **`HashedApiTokenAuthenticator` checks the shape before any query** (`ApiToken::PATTERN`), looks the row up by primary
  key in the tenant of the host, compares with `hash_equals`, and answers every failure with the same bare 401 JSON
  (`bootstrap/app.php` renders `AuthenticationException` of the `api-token` guard, whatever the client accepts); the
  reason goes only to the log (`api_token.auth_failed`). 20 failures a minute per tenant and IP are refused with 429
  before any lookup; `last_used_at` is written at most once a minute (cache `add`). `AuditActorResolver` asks the
  token guard only `hasUser()`, so a console write never runs the authenticator. Enforced:
  `ApiTokenAuthenticationTest`.
- **The installation operator only revokes a tenant's token, and only with the token itself**
  (`tenants:api-token:revoke {tenant} --operator=`, the token read from a hidden prompt or standard input, never
  argv): audited as `support` with the operator's name, `revoked_by` empty. Admins are warned once per token 14 days
  before expiry (`api-tokens:notify-expiring`, daily; the conditional update of `expiry_notified_at` is the guard).
  Enforced: `ApiTokenLifecycleTest`.
- **`Permission::ManageFlow` is `@deprecated`** and kept for backward compatibility; use the granular
  flow permissions (`ManageFlowDefinitions`, `PublishFlow`, `ViewFlowSessions`, `ManageFlowGroups`,
  `ManageTranslations`) for new checks.
- **Role priority decides who may assign which role** (`CreatePendingUserService`). New role
  flows must use it.
- **A change to a system role's permissions in `RoleEnum` needs a tenant migration.** `RoleSeeder`
  runs only at provisioning and through the manual `ops:tenants-seed-acl`, never on deploy, so
  existing tenants keep the old set. The migration hard-codes the `web` guard (migrations may not
  read config) and leaves custom roles alone; see
  `2026_09_22_000001_grant_manage_channels_to_content_manager.php` (one role) and
  `2026_11_20_000001_grant_automation_and_credential_permissions.php` (several permissions, two roles, each role
  guarded on its own). The tenant's permission cache is
  forgotten when the migrations end (`TenantDatabaseManager::runMigrations()`), so the migration does not touch
  it. Review only.
