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
  - "app/Filament/Resources/Users/**"
  - "app/Filament/Resources/Roles/**"
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
reviewed_at: 2026-10-05
---
# Staff

## Responsibility

Staff owns the tenant's operators: staff users with Spatie roles and permissions, account
activation by token, activation and deactivation rules, and notifications to staff (in-app or
email) raised by Flow's staff-notify node.

## Boundaries

- Consumers: the `User` model and the `Permission` enum are imported across all domains, mostly
  by policies.
- Depends on Tenancy (`TenantSwitcher`, context and repository contracts), the `Assistant` model
  (assignments) and Flow's `FlowSession` (notification job). Staff has import cycles with Tenancy
  (through provisioning), Assistant and Flow.
- `app/Providers/StaffDomainServiceProvider.php` is empty and not registered; the real provider
  is `app/Providers/StaffServiceProvider.php`.

## Entry points

- `app/Providers/StaffServiceProvider.php`: service bindings, policies, and a `Gate::before`
  that grants admins everything.
- Activation: `/activate` routes (`routes/web.php`), `Services/ActivationTokenService.php`,
  `Jobs/SendActivationEmailJob.php`.
- Notifications: `Jobs/SendStaffNotificationJob.php` (queue `messaging.system`), the staff
  notifier registry.
- `Http/Middleware/EnsureUserIsActive.php` (both panels), `database/seeders/RoleSeeder.php`.
- Admin UI: with `UI_INERTIA=true`, `app/Http/Controllers/Admin/{UserController,RoleController}.php`
  over `Services/StaffUserService.php` and `Services/StaffRoleService.php` (roles are written by
  `RoleWriterService`), pages in `resources/js/pages/Console/{Users,Roles}`; with it off,
  `app/Filament/Resources/{Users,Roles}`.
