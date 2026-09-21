---
id: glossary-staff
type: glossary
status: active
summary: Staff user (User), UserStatus vs is_active, roles and priority, Permission enum, activation token
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
---
# Staff glossary

## Staff user

A person operating the tenant's admin and assistant panels; the `User` model in the tenant
schema. Informal synonyms: user, operator, admin. "User" in code always means a staff user,
never a contact.

## UserStatus

The account lifecycle: `pending` (not activated), `active`, `suspended`. Separate from
`is_active`, the admin's on/off switch.

## Role

`RoleEnum`: `admin`, `content_manager`, `analyst`. A role's `priority` decides who may assign
which role.

## Permission

The `Permission` enum that policies check across all domains.

## Activation token

A one-time token for setting the first password: stored as SHA-256, valid for 72 hours.

## Staff notify target

Who a staff notification goes to: an assistant's staff, a role, or listed users.
