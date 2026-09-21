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
- **Role priority decides who may assign which role** (`CreatePendingUserService`). New role
  flows must use it.
