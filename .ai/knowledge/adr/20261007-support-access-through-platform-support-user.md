---
id: adr-20261007-support-access-through-platform-support-user
type: adr
status: accepted
date: 2026-10-07
domains:
  - tenancy
  - staff
paths:
  - app/Domains/Tenancy/Infrastructure/CoreSupportAccess.php
  - app/Domains/Staff/Http/Controllers/SupportAccessController.php
  - app/Domains/Staff/Services/PlatformSupportUserService.php
  - app/Domains/Staff/Policies/PlatformSupportProtection.php
  - app/Providers/StaffServiceProvider.php
---
# A platform operator enters a tenant through one platform support user, with a single-use token posted to the tenant's host

## Context

An operator package (the closed SaaS shell) needs its staff to open a tenant's admin panel to help
a customer. Core owns the tenant's users and sessions, which live in the tenant's schema and in a
cookie scoped to the tenant's host, so only a request to `<slug>.<base>` can open one. The shell
cannot write those sessions itself, and Core must not learn the shell's accounts.

Forces: the customer must see who was in their workspace; the operator must not be able to
impersonate a customer's administrator (that would blur whose actions are whose); a link carrying
a secret leaks through browser history, web-server logs and referrers; Core has no audit log yet
(the `audit-log` spec is an idea).

## Decision

- Foundation's `SupportAccessInterface::issue()` is implemented by Core (`CoreSupportAccess`). It
  returns a grant: the URL of `POST /support/enter` on the tenant's host and a single-use token.
  Core checks only that the flag is on and the tenant exists and is active; deciding which operator
  may ask is the caller's job, and Core records the operator it is given.
- Support access works in `host` mode only: with one tenant per deployment there is no tenant host to
  send the browser to, so `issue()` throws `disabled()` in `single` mode too.
- The flag `tenancy.support_access.enabled` (env `SUPPORT_ACCESS_ENABLED`) is off by default. Off,
  `issue()` throws `SupportAccessUnavailableException::disabled()` and the route answers 404. The
  operator package turns it on in host mode; Core does not check that a package exists.
- Tokens live in the landlord table `support_access_tokens`, owned by Tenancy. Only the sha256 hash
  is stored. A token lives 60 seconds and is consumed by one conditional UPDATE (`used_at IS NULL`,
  not expired, same tenant) that must change exactly one row; a scheduled command prunes old rows.
  The other domains reach the table only through `SupportAccessRedeemerInterface`.
- The token travels in the POST body, never in a URL. The route sits in the `web` + `tenant` group,
  is exempt from CSRF (the token is the proof; the request comes from another site), is throttled
  per IP, regenerates the session after login and never sets "remember me".
- The operator becomes the tenant's **platform support user**: one per tenant, created on the first
  entry (`users.is_platform_support`), named "Platform support", on the undeliverable address
  `support@platform.invalid`, with a null password (no form login) and the `admin` role. It is the
  same account for every operator; the operator is told apart by the session record and the entry.
- Nobody changes or removes that user: `Gate::before` returns `false` for update, delete,
  deactivate, activate and role changes when the target is the support user, ahead of the admin
  bypass; `UserService` refuses the same; the users table authorizes each selected record on bulk
  delete. It is not counted as the last active administrator and must not be counted against the
  staff limit.
- The flag is also the emergency stop: with it off, `EndExpiredSupportSession` ends every open support
  session on its next request. The same middleware signs out a platform support user whose session
  has no `support_access` record, skips the entry route (an old support cookie must not swallow a
  fresh grant), and answers an ended session by request kind: redirect, 401 for JSON, an Inertia
  location (409) and 419 for Livewire.
- The session carries a `support_access` record (operator, entry id, expiry). A middleware ends the
  session 60 minutes after it began regardless of activity. A banner in both panels names the
  operator and offers sign-out.
- The tenant sees it: each entry is a row in the tenant table `support_access_entries` (operator
  name and email, IP, entered and left times). Core freezes new Filament screens, so the log is
  recorded now and shown by a screen in the new Inertia UI (spec `ui-foundation`); until then the
  tenant sees the platform support user, badged "Platform support", in its users list. The entry keeps the operator's `operator_ref` too. A `Logout` listener records the
  leave however the session ended; entries abandoned without a logout are closed by
  `support-access:prune` (daily) as left one hour after entering.
- The support user is not one of the tenant's people: it is left out of staff notification recipients,
  the builder's staff picker and the dashboard staff count. Addresses on `.invalid` are reserved, so no
  tenant user can take the support address; the model refuses to delete it (row deletes; dropping a
  tenant's schema removes it with everything else).

## Alternatives

- Entering as an existing administrator of the customer: mixes the customer's actions with the
  operator's; rejected by the owner.
- A token in a GET link: ends up in history, logs and referrers; a POST body does not.
- A signed URL with no storage: not single use within its lifetime.
- Keeping tokens in the tenant schema: issuing happens on the base domain, with no tenant; a
  landlord table with an atomic consume is simpler.
- A password for the support user: it could leak or be changed; null password and token-only entry
  remove both.
- Waiting for the audit log: delays the feature; the minimal entries table is absorbed by it later.

## Consequences

- The SaaS ADR `no-cross-host-sign-in` forbids a package from issuing a sign-in on a tenant host;
  the SaaS task adopting this contract must supersede it with an ADR for support access (not for
  customer sign-up).
- A mistake in the protection means a customer can delete the support user (an inconvenience: it is
  recreated on the next entry); the opposite mistake gives the operator nothing beyond admin rights
  they already have.
- The support user is an admin in every tenant: anything a tenant admin can do, the operator can
  do. The tenant's record of entries is the control, not a reduced role.
- Redis-backed sessions are not removed by `UserService::deactivate()`; the support user is never
  deactivated, so this does not affect it.
- `User::scopeCountedForLimit()` excludes the support user, so it takes no staff place;
  `PlatformSupportUserService` is a listed creator in the record-creation architecture rules and is
  never refused by the limit.
- Core has no password reset flow, so there is nothing to refuse for the support user; a reset flow
  added later must refuse it.

Source: spec `tenant-quotas` (Core), task `support-access-contract`, decided with the owner on
2026-10-06.
