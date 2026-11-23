---
id: domain-staff
type: domain
status: active
summary: Staff users, roles and permissions, activation tokens, staff notifications
domains:
  - staff
topics: []
load: domain
paths:
  - "app/Domains/Staff/**"
  - app/Providers/StaffServiceProvider.php
  - database/seeders/RoleSeeder.php
  - "tests/*/Domains/Staff/**"
  - "database/migrations/tenant/*staff_users*"
  - "database/migrations/tenant/*password_reset_tokens*"
  - "database/migrations/tenant/*create_sessions_table*"
  - "database/migrations/tenant/*permission_tables*"
  - "database/migrations/tenant/*roles*"
  - "database/migrations/tenant/*activation_tokens*"
  - "database/migrations/tenant/*notifications_table*"
  - app/Http/Controllers/Admin/UserController.php
  - app/Http/Controllers/Admin/RoleController.php
  - "app/Http/Requests/Admin/**"
  - "database/migrations/tenant/*builder_mode*"
reviewed_at: 2026-11-23
---
# Staff

## Responsibility

Staff owns the tenant's operators: staff users with Spatie roles and permissions, account
activation by token, activation and deactivation rules, and notifications to staff (in-app or
email) raised by Flow's staff-notify node. It also owns the tenant's machine credentials: tenant
API tokens, their `api-token` guard and stack, and their screen.

## Boundaries

- Consumers: the `User` model and the `Permission` enum are imported across all domains, mostly
  by policies.
- Depends on Tenancy (`TenantSwitcher`, context and repository contracts), the `Assistant` model
  (assignments) and Flow's `FlowSession` (notification job). Staff has import cycles with Tenancy
  (through provisioning), Assistant and Flow.
- `app/Providers/StaffDomainServiceProvider.php` is empty and not registered; the real provider
  is `app/Providers/StaffServiceProvider.php`.

## Entry points

- `app/Providers/StaffServiceProvider.php`: service bindings, policies, the `api-token` guard and
  rate limiter, and the Staff `Gate::before` (`Policies/StaffGate.php`: support-user protections,
  fail-closed tokens, operator screens, the admin bypass), registered ahead of every other.
- Tenant API tokens: `Models/ApiToken.php`, `Services/ApiTokenService.php` (the only writer),
  `Services/HashedApiTokenAuthenticator.php` behind `Contracts/ApiTokenAuthenticatorInterface.php`,
  `Support/ApiTokenSettings.php`, the `api-token` stack and `routes/api.php` (`bootstrap/app.php`),
  the screen `app/Http/Controllers/Admin/ApiTokenController.php` with
  `resources/js/pages/Console/ApiTokens`, the commands `tenants:api-token:revoke` (operator) and
  `api-tokens:notify-expiring` (daily, `Services/ApiTokenExpiryNotifier.php`).
- Activation: `/activate` routes (`routes/web.php`), `Services/ActivationTokenService.php`,
  `Jobs/SendActivationEmailJob.php`.
- Notifications: `Jobs/SendStaffNotificationJob.php` (queue `messaging.system`), the staff
  notifier registry (`StaffNotifierInterface::send(User, StaffMessage)` — text and an optional link), the bell's
  `Services/StaffNotificationInbox.php`, and its live channel in `Live/` (announced by
  `Listeners/AnnounceStaffNotification` on every in-app notification).
- `Http/Middleware/EnsureUserIsActive.php` (the console stacks), `database/seeders/RoleSeeder.php`.
- Personal settings are one column each on `users`, written for the signed-in user only: `locale`,
  `pinned_applications`, `onboarding_checklist_hidden`, and `builder_mode` (enum `Enums/BuilderMode`, `tree`/`graph`,
  `null` read as tree; written by the builder's `PUT /builder/preferences`, `adr-20261123-flow-builder-graph-mode`).
- Admin UI: `app/Http/Controllers/Admin/{UserController,RoleController}.php`
  over `Services/StaffUserService.php` and `Services/StaffRoleService.php` (roles are written by
  `RoleWriterService`), pages in `resources/js/pages/Console/{Users,Roles}`.
