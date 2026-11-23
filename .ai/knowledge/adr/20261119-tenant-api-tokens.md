---
id: adr-20261119-tenant-api-tokens
type: adr
status: proposed
date: 2026-11-19
domains:
  - staff
  - tenancy
  - audit
paths:
  - "app/Domains/Staff/**/*ApiToken*"
  - "database/migrations/tenant/*api_tokens*"
  - "app/Http/Middleware/*ApiToken*"
  - app/Domains/Staff/Policies/PlatformSupportProtection.php
  - app/Infrastructure/Audit/AuditActorResolver.php
  - app/Providers/StaffServiceProvider.php
  - app/Domains/Staff/Enums/Permission.php
  - config/auth.php
  - bootstrap/app.php
summary: "Tenant API tokens: tenant-owned, hashed in the tenant schema, found by host, Permission abilities via Gate, 90/365-day expiry"
---
# A tenant API token is a tenant's own hashed credential in its schema, found on the tenant's host and authorized by `Permission` through the Gate

## Context

Two specs need a machine credential for a tenant: `flow-automations` (phase 7, the API trigger that
starts a published flow from an external system) and `mcp-server` (phase 1, the bearer token an
external AI agent presents). Left alone, each would invent its own scheme (`mcp_tokens`, a trigger
secret), with two vocabularies of rights and two places to revoke. The project has no Sanctum or
Passport installed and adds dependencies only by agreement. Forces:

- In `host` mode a tenant is resolved from `<slug>.<base_domain>` before anything else runs; the
  landlord connection is opened only inside Tenancy (`domains/tenancy/RULES.md`).
- Rights are the `Permission` enum checked through `Gate`; admins pass every check through
  `Gate::before`, and the platform support user is protected ahead of that bypass
  (`PlatformSupportProtection`).
- An integration outlives the person who set it up: staff leave, roles change.
- The audit log (`adr-20261111-audit-log-explicit-recording`) names an actor for every recorded
  action and knows only `staff`, `support` and `system`.
- The project already stores a high-entropy secret only as its SHA-256 hash
  (`support_access_tokens`, redeemed by hash and tenant in one conditional update).

## Decision

1. **The token belongs to the tenant, not to a staff user.** It is an integration of the tenant.
   `created_by` records who issued it, for the audit and the list, never for its rights.
   Deactivating or deleting the issuer does not revoke the token; the token list shows the issuer
   and their status (owner, 2026-11-19).
2. **Storage: table `api_tokens` in the tenant's schema, owned by the Staff domain.** No landlord
   table: the tenant is known before the token is checked (5), so a token of another tenant is
   simply not found, and deleting a tenant takes its tokens with the schema. Columns: `id` (ULID in
   a `uuid` column), `name`, `token_hash` (char 64, unique), `last_four`, `abilities` (json),
   `created_by` (nullable uuid), `expires_at` (not null), `revoked_at`, `revoked_by`,
   `last_used_at`, `created_at`.
3. **Shape: `fpt_<id>_<secret>`.** `fpt_` is a fixed prefix secret scanners can recognise; `<id>` is
   the row's ULID (lookup by primary key); `<secret>` is 40 base62 characters from a CSPRNG (about
   238 bits). Only `sha256(secret)` is stored and compared with `hash_equals`; a high-entropy secret
   needs no bcrypt or argon. Before any query the parts are checked: the prefix, `<id>` as a
   well-formed ULID and `<secret>` as 40 base62 characters; anything else is the same 401 and never
   reaches the database (a malformed value in a `uuid` column is a Postgres error, i.e. a 500 — the
   trap `ForgetInvalidAuthenticatedSession` already guards for sessions). The plain token is shown
   once, at issue, and never again. It travels
   only in `Authorization: Bearer` — never in a query string, a form body or a path.
4. **One table, own implementation, no new dependency** (owner, 2026-11-19). The pattern of
   `support_access_tokens` is reused; Sanctum is not added (Alternatives).
5. **A request finds its tenant the way the console does, then its token.** In `host` mode the host
   `<slug>.<base_domain>` resolves the tenant; in `single` mode the configuration does; the base
   domain and platform subdomains reach no tenant (404). The token is checked inside that tenant
   only: a token presented on another tenant's host is a 401 because it is not in that schema.
   There is no tenant taken from the token and no fallback. API and MCP routes are not in the `web`
   group: they run a stateless stack of their own, `api-token` (`ResolveTenantContext` → tenant →
   `auth:api-token` → throttle → access mode), with no session, CSRF or cookies. A failed
   authentication is always the same bare 401; missing, unknown, expired and revoked are told apart
   only in the log.
6. **MCP is served per tenant: `https://<slug>.<base_domain>/mcp`** (owner, 2026-11-19), which
   settles the open question of `mcp-server` against a single platform URL.
7. **Rights are `Permission` values; there is no second scope vocabulary.** `abilities` holds
   `Permission` values, and a surface asks `Gate`, as the MCP spec requires.
   - **A token surface authorizes by `Permission` value, never by a policy ability.** A tool or an
     endpoint asks `Gate::allows(Permission::X->value)`; it never calls `Gate::authorize('view',
     $model)` for a token, because the token branch below answers `false` to every ability that is
     not a `Permission` value it holds. Policy classes are therefore not consulted for a token in v1.
     This narrows the MCP spec's "reuse the `Permission` enum and Policy classes" to the enum (the
     spec text is updated by its owner). A hard refusal a policy holds for everyone (for example a
     `create` that no one may perform) is repeated by the surface itself.
   - **No escalation, on issue and on rotation.** The actor grants only permissions they hold
     themselves. A non-admin issuer may grant only permissions whose reach is not narrowed for them
     by a record-level policy (assigned assistants and the like), since the token would reach the
     whole tenant; which permissions those are is a method on `Permission` next to `isSensitive()`.
     An administrator may grant any permission a token may hold.
   - **Rotation never widens a token.** A successor inherits at most the permissions of the token
     it replaces: its abilities are a subset of the predecessor's, never a superset, so rotation
     may narrow a token and nothing else. The successor's abilities also pass the issue rules
     above against the rotator — each one held by the rotator, and for a non-admin rotator none
     narrowed by a record-level policy. A rotator who cannot grant every ability of the
     predecessor may rotate only to the subset they can grant, or leave the rotation to someone
     who can. Widening a token's rights is never a rotation: it is a new token, issued by someone
     who holds those permissions.
   - Revoking needs only `ManageApiTokens` in the console; the operator's incident revoke is (8).
   - Sensitive permissions (`Permission::isSensitive()`) are chosen separately and marked so in the
     screen.
   - A new permission `StartFlowRuns` (start a run of a published flow through the API trigger)
     exists so an integration does not need the planned `ManageAutomations`.
   - The guard `api-token` returns a principal `ApiToken` (an `Authenticatable`, not a `User`). A
     `Gate::before` branch for `ApiToken` answers from its abilities and is **fail-closed**: an
     ability the token holds is `true`, any other ability is `false`, never `null` (a policy must not
     get a second say on a principal it was not written for). It runs after
     `PlatformSupportProtection` and before the `CoreOperatorAccess::GATE_ABILITIES` branch and the
     admin bypass, which matches only `User`, so a narrow token never becomes an admin and never
     reaches the operator screens. A test enforces this.
   - A token may never hold `ManageApiTokens` (a token that mints tokens renews itself through
     rotation), `ManageRoles`, `ManageUsers` or `ViewOperatorScreens`; issuing refuses them and the
     `Gate::before` branch refuses them even if a row holds them.
   - Record-level policies (assigned assistants and the like) do not apply to a token: it sees the
     whole tenant within its abilities. There is no binding to assistants in v1
     (owner, 2026-11-19); a narrowing column may be added later without changing the model.
8. **Who issues and revokes.** A staff user with the new sensitive permission `ManageApiTokens`
   (admins through `Gate::before`; existing tenants receive the permission row by a tenant data
   migration, as `2026_09_22_000001_grant_manage_channels_to_content_manager` and
   `2026_11_11_000002_add_view_activity_log_permission` did). The screen is a console page on the
   kit. The platform support user cannot issue tokens, because a token would outlive the
   operator's 60-minute visit. `PlatformSupportProtection` today guards the support user only as the
   *target* of an ability; this needs a new check keyed on the *actor*, placed in `Gate::before`
   ahead of the admin bypass, that refuses the support user `ManageApiTokens` **and every ability
   on the `ApiToken` resource** (issue, rotate, revoke, view). The token screen authorizes by the
   `ManageApiTokens` value only; should an `ApiTokenPolicy` appear later, its abilities are refused
   to the support user by the same check, revoking included: in the console the support user
   does nothing with tokens. It narrows
   `adr-20261007-support-access-through-platform-support-user`, which is amended accordingly.
   **The installation operator may revoke a tenant's token, and only revoke it** (incident
   response: a token leaked and the tenant's admins cannot be reached in time). The operator never
   issues, rotates or reads a tenant's token — not the list, the names, the abilities, the issuers
   or `last_four`. The revoke is an operator action outside the tenant console: it is given the
   leaked token itself, which carries its row's id (3); the row is found inside the tenant's schema
   by that id and revoked only if the secret matches its hash, so the operator learns nothing they
   did not already hold and cannot revoke by guessing ids. In Core it is an operator command over a
   tenant; in the SaaS shell the same action is a panel action through a Foundation contract, built
   by the first task that needs it. As with support access, Core records the operator it is given:
   the revocation is audited in the tenant's log with `actor_kind = support` and the operator's
   name (no `support_entry_id`, since there is no visit), and `revoked_by` stays empty because no
   staff user acted. The operator issues nothing: no contract for issuing or reading tokens exists,
   and this ADR adds none.
   Revoking sets `revoked_at`/`revoked_by`, keeps the row for history and takes effect on the next
   request (no authentication cache: one primary-key lookup per request).
9. **Lifetime and rotation.** An expiry is mandatory: 90 days by default, at most 365, as a platform
   setting with that ceiling; there are no tokens without one (owner, 2026-11-19). Rotation issues
   a successor with the same name and, by default, the same abilities — narrowed when the rotator
   chooses or must, never widened (7) — and shortens the predecessor's life to a grace window
   (24 hours by default, 0 allowed): its `expires_at` becomes the earlier of its current value and
   now plus the grace, so rotation never extends a token. An expired or revoked token cannot be
   rotated (a new token is issued instead). A secret is never changed in place. Fourteen
   days before expiry the tenant's admins are notified through the existing staff notifications.
10. **Rate limits.** A named limiter `api-token` keyed by tenant id and token id (60 requests a
    minute by default, a platform setting); MCP may have a limiter of its own on the same key.
    Failed authentications have a separate per-IP limit against guessing, applied by the
    authenticator (13) itself, since a failed request never reaches the throttle after `auth`. Plan quotas are not here:
    the API trigger goes through the run admission and rate breaker of `flow-automations`, and the
    operator decides the plan. `last_used_at` is written at most once a minute per token.
11. **Access mode.** The `api-token` stack asks the tenant's access mode as the console does, and a
    stopped tenant refuses writes with the answer `RefuseWritesWhenTenantStopped` gives. That
    middleware decides by HTTP method, which fits the API trigger (a POST starts a run). It does not
    fit MCP, where every call is a POST: there a call's write-ness is decided per tool (a read tool
    and `tools/list` still answer on a stopped tenant, a write tool is refused with the same
    status), by the MCP surface, not by the method.
12. **Audit.** Issue, rotation and revocation are `AuditAction`s in the tenant's log, with the token's
    name and `last_four` and never its secret or hash; the actor is the staff user, or for an
    operator's revoke the operator Core is given (8). Actions taken
    through a token are recorded with a new `actor_kind = api_token` and the label
    `API · <token name>`: `AuditActorResolver` learns to read the `api-token` guard. The per-call log
    of a surface stays with the surface (`mcp_audit_log` for MCP, the run and its `trigger_id` for
    the API trigger); `token_id` is the shared key.
13. **A seam for OAuth.** Authentication sits behind a Staff contract,
    `ApiTokenAuthenticatorInterface::authenticate(Request): ?ApiToken`. Should an MCP marketplace
    need OAuth 2.1, a resource server becomes another implementation returning the same principal;
    the callers do not change.

## Alternatives

- **Sanctum personal access tokens.** The same shape (`id|secret`, SHA-256, abilities,
  `expires_at`). Rejected: a new dependency; `tokenable` is a morph to a user, i.e. a staff user's
  token; abilities are free strings, not `Permission`; `last_used_at` is written on every request;
  its SPA-cookie half is not needed. The own implementation is about a hundred lines of a pattern the
  project already runs. Choosing Sanctum later changes the storage, not this model.
- **OAuth 2.0 client credentials / Passport.** A heavy dependency, signing keys and their rotation,
  OAuth endpoints on every tenant host — too much for "paste a token". Not rejected forever: the
  seam in (13) leaves room for OAuth 2.1 for MCP.
- **A landlord table and the tenant taken from the token (one URL for the platform).** It would give
  MCP one address and survive a slug rename. Rejected: it goes against host-based resolution, adds a
  cross-tenant lookup where a bug is a leak between tenants, needs cleanup when a tenant is removed,
  and reaches the landlord outside Tenancy's contracts. Should one URL be wanted later, a landlord
  index `token_id → tenant_id` is enough; the rows can stay in the schema.
- **A staff user's token (per user).** Rights would be the intersection of the token and the user's
  current permissions, and accountability is plain. Rejected: the integration breaks when the
  person leaves or changes role — the wrong meaning for an API trigger.
- **A service user per token (like the platform support user).** Policies and Spatie would work
  unchanged, assistant assignments included. Rejected: pseudo-staff in `users` must be kept out of
  the limit, pickers, notifications and password sign-in and protected from edits — the support user
  shows what that costs. A separate principal is chosen.
- **Token surfaces call policies, with policy abilities mapped to the token's `Permission`
  values.** It would keep the MCP spec's "Policy classes" literally. Rejected for v1: every policy
  ability (`view`, `reply`, …) would need a defined mapping for `ApiToken`, and a policy written for
  a `User` would quietly start deciding for a principal it was not written for. Surfaces ask by
  `Permission` value instead (7); record narrowing later brings the mapping with it.
- **Rotation copies the predecessor's abilities unchecked.** Simple, and it is what "rotate" means
  in most products. Rejected: anyone with `ManageApiTokens` would receive a plaintext token with
  rights they do not hold (a reader of no transcripts rotating a token that reads every transcript).
- **The support user revokes in the console.** Rejected: revoking from the console needs the token
  list, i.e. reading the tenant's tokens, and it would split the support-user check into "every
  ability but one". The operator's revoke takes the leaked token itself instead (8).
- **Stateless JWT.** No immediate revocation without a denylist; keys and their rotation are needed.
- **A scope vocabulary of its own (`flows:trigger`, `mcp:read`).** A second dictionary of rights that
  drifts from `Permission`; the MCP spec rejects it explicitly.

## Consequences

- Both specs get one model: MCP phase 1 builds on `api_tokens` instead of `mcp_tokens` (the spec text
  is updated by its owner, not by this ADR), and the API trigger uses the same guard and stack.
- Renaming a slug breaks integrations: a former slug's alias redirects only GET/HEAD, so a POST to the
  old host is a 404. This is accepted (owner, 2026-11-19); the rename screen and the token screen
  warn that API and MCP addresses change with the slug. Serving API routes on an alias host during
  the redirect window would be a change to the Tenancy rule and a task of its own.
- Record-level policies do not restrict a token, and token surfaces do not call policies. Narrowing a
  token to records later (by assistant, say) is a change to this ADR: a narrowing column and a defined
  mapping of policy abilities for `ApiToken`, not a policy that quietly starts accepting it.
- New permissions `ManageApiTokens` (sensitive) and `StartFlowRuns`, with a data migration for
  existing tenants; a new actor-keyed check in `Gate::before` refuses the support user
  `ManageApiTokens` and every ability on the `ApiToken` resource — issue, rotate, revoke, view
  (amendment to `adr-20261007-support-access-through-platform-support-user`).
- The audit log gains the actor kind `api_token`, shown by `AuditActorResolver` and the log screen,
  and records an operator's revoke as a `support` actor without a visit (amendment to
  `adr-20261111-audit-log-explicit-recording`).
- An operator's incident revoke (8) is one more operator action over a tenant: a Core command now,
  a Foundation contract and a SaaS panel action when the shell needs it.
- Decided after the gate, in review, and confirmed by the owner on 2026-11-19 ("Да, всё по
  рекомендациям" — "Yes, everything per the recommendations"): token surfaces authorize by
  `Permission` value and never call policies, narrowing the MCP spec's "Policy classes" (7); a
  non-admin issuer grants no permission a record-level policy narrows for them (7); rotation
  inherits at most the predecessor's permissions and passes the issue rules against the rotator
  (7, 9); the support user is refused every token action, revoking included (8); the installation
  operator may revoke a leaked token and never issue, rotate or read one (8). The rest was approved
  at the gate.
- Changing the storage or resolution later (2, 5) is the expensive direction: one URL needs the
  landlord index and a migration of issued tokens. The token format (3) cannot change after the first
  tokens are issued without reissuing them.
- Nothing is built by this ADR. The table, guard, stack, screen and permissions are built by the
  first task that needs them — "API trigger" (`flow-automations`, phase 7) or "Token issuance"
  (`mcp-server`, phase 1) — and the second reuses them.

Source: task `tenant-api-token-adr`, specs `flow-automations` and `mcp-server`; design approved by the
owner at the gate on 2026-11-19 ("Да, всё одобряю" — "Yes, I approve everything"); the review's
decisions confirmed by the owner on 2026-11-19 ("Да, всё по рекомендациям").

## Built 2026-11-20 (task `tenant-api-tokens`)

The model above is built, without an endpoint (the API trigger is the first one). Two details the text did not
fix, settled at the owner's gate of `flow-api-trigger` (2026-11-20) or by the task's agent:

- `api_tokens` has one more column than (2): `expiry_notified_at`, the guard of "once" for the expiry notice (9),
  also set on a rotated predecessor so its shortened life sends no notice.
- The `api-token` stack is `tenant` → `auth:api-token` → `throttle:api-token` → `RefuseWritesWhenTenantStopped`:
  the `tenant` middleware resolves the tenant of the host itself (as `ResolveTenantContext` does for `web`), so the
  latter is not repeated (5).
- `manage_api_tokens` and `start_flow_runs` are granted to the system admin role only, as the seeder does; no other
  role. `Permission::isNarrowedByRecordPolicy()` treats every permission but four tenant-wide ones as narrowed,
  `StartFlowRuns` included, so only an administrator puts it on a token (7).
- The Staff `Gate::before` is registered as soon as the Gate is resolved, ahead of the permission package's own
  before-callback, which would otherwise answer `true` for the support user's admin-role permissions (7, 8).
