---
id: adr-20261119-credentials-store
type: adr
status: accepted
date: 2026-11-19
domains:
  - flow
paths:
  - "app/Domains/Credentials/**"
  - app/Domains/Flow/Call/Transports/HttpTransport.php
  - app/Http/Controllers/Admin/CredentialController.php
  - app/Http/Controllers/Admin/CredentialOAuthController.php
  - app/Console/Commands/Credentials/RefreshOAuth2GrantsCommand.php
summary: Why a tenant's saved credentials live in their own Credentials domain, are bound to a mandatory list of allowed hosts, reach a call only as a masked, never-redirected, Foundation-contract secret
reviewed_at: 2026-11-25
---
# Saved credentials are a Credentials domain; a call applies one only to an allowed host and masks it on the way out

## Context

A `call` node needed to authorize its request without the key sitting in the flow definition, where it is
copied into drafts, snapshots and exports and is visible to everyone who edits flows. The value had to be
stored once per tenant, shown to nobody afterwards, and applied by the runtime. The author of a flow is
less trusted than the owner of the credential: someone who may edit a flow can point its `call` at a server
of their own and read the key in that server's access log, which no masking of results can prevent.

## Decision

The owner approved the design at the human gate on 2026-11-19.

- **D-1. Allowed hosts are mandatory (at least one per credential).** `CredentialSecret::$allowedHosts` is
  checked by `HttpTransport` before any connection (`credential_host_denied`); entries are a host name or
  `*.domain` (`HostMatcher`; the wildcard matches subdomains, not the bare domain). Loosening the rule later
  (hosts optional) breaks nothing; tightening it would break every credential saved without hosts.
- **D-2. A domain of its own, `app/Domains/Credentials`.** Credentials are needed by Flow, and later by
  Solutions and connectors; Flow depends on the Foundation contract, not on a Flow-local store.
- **D-3. Temporary permissions.** The console screen needs `ManageSettings` (`CredentialPolicy`, every
  ability); the builder's picker endpoint (id, name, type only) needs what `FlowDraftPolicy::viewAny` needs,
  that is `ManageFlowDefinitions`. They are replaced by `ManageCredentials` / `UseCredentials` when the
  permissions roadmap item is done.
  *Superseded by the task `flow-automation-permissions`:* the screen needs `ManageCredentials`; the picker, a
  call test with a credential, and publishing a new credential reference need `UseCredentials`.
- **D-4. Audit.** `CredentialWriter` records `credential.created`, `credential.updated` and
  `credential.deleted` through `AuditTrailInterface` in the write's transaction. An entry names the credential,
  its type and hosts and whether the secret was replaced, never a value.
- **D-5. No redirects with a credential.** `HttpTransport` calls `withoutRedirecting()` when a credential is
  applied; a 3xx is returned as the result. A custom key header or a query key would otherwise follow a
  redirect to another host.
- **D-6. The contract came first, in Foundation 0.43.** `Fapost\Foundation\Credentials` holds
  `CredentialStoreInterface`, the type interfaces and the DTOs; `CallRequest` gained an optional
  `?CredentialSecret $credential`. Core consumes it through `composer.json` `^0.43`.

How the secret is kept and applied:

- **The secret never leaves a call in the clear.** `CredentialSecret` and `HttpAuthorization` keep their values
  private behind accessors, mask them in `__debugInfo()` and refuse `__serialize()`, so a `CallRequest` that
  carries one cannot be queued or cached. `CallNodeHandler` puts it in the request's own field, never in
  `parameters` or `options`, which are the node's input and what a trace records.
- **Storage.** `credentials` is a tenant-schema table with a `tenant_id` column and unique
  `(tenant_id, name)`. The `secret` column is `encrypted:array` with the application key, so rotating
  `APP_KEY` needs `APP_PREVIOUS_KEYS`; the model hides it. Console props, flash and the audit carry no secret
  field, and secret form fields are in the exception handler's `dontFlash` (`bootstrap/app.php`).
- **OAuth2 tokens** are cached in Redis, encrypted, under `credentials:oauth2:{tenant}:{credential}:{version}`
  (version is the credential's `updated_at`, so an edit orphans the old token); a 401 from the target and the
  tenant forgetter drop it. The token request goes through Flow's `GuardedHttpClient`.
- **`credential_id` is never templated.** `CallNodeHandler` reads it as written, so a flow variable cannot
  select another credential. A credential is applied only by the `http` transport (`credential_unsupported`
  otherwise). A missing one is `credential_not_found`, an unreadable one `credential_unavailable`.
- **Masking happens at the transport's exit.** `HttpTransport` runs the whole `CallResult` (payload, metadata,
  error messages, exception text) through `SecretRedactor` with `HttpAuthorization::secrets()` before it
  reaches the ledger, state or logs. Residual risk: a secret the target echoes in a transformed form
  (URL-encoded, re-encoded base64) is not found by a substring match, and values shorter than 4 characters are
  skipped (the screen refuses secrets under 8).
- **The author's routing headers are dropped** when a credential is applied (`Host`, `X-Forwarded-Host`,
  `Forwarded`, `X-Original-Host`, `X-Host`), together with any author header of the credential's own name and
  the inline `auth.*` parameters, so a shared ingress cannot route an authorized request to another site
  behind an allowed host.

## Amendment: authorization code grants (task `flow-credentials-oauth2`, 2026-11-23)

The owner approved the design at the human gate ("all per the recommendations"). A credential type
`oauth2_authorization_code` gets its tokens from a person's consent in the console.

- **The grant lives in the database.** A tenant table `credential_oauth_grants`, one row per credential
  (`credential_id` primary key, cascading with the credential), with the access and refresh tokens `encrypted`
  with the application key and hidden in the model, their expiries, who connected it and when, and a status
  (`connected`, `reconnect_needed`). The rejection of "a token in a database column" above stands for client
  credentials; here the refresh token is the only copy of a person's consent, so losing it (a Redis eviction, a
  flush) would mean reconnecting by hand. A refresh writes the row, but only when the access token ran out.
- **Each tenant registers its own client** at the provider; client id and secret are the credential's fields. A
  platform-wide OAuth application belongs to the SaaS layer, later.
- **The callback is one URL per tenant host**, `GET /admin/credentials/oauth/callback` in the admin stack (tenant,
  session, `auth`, `ManageCredentials`). The pending attempt (state, PKCE S256 verifier, redirect URI, credential
  and its version, user, ten-minute expiry; at most five) is kept in the session and taken out once. The callback
  removes its query from the request before the session records the "previous URL", so the code does not land in
  the session. A stopped tenant is refused in the controller, since the callback is a GET.
- **Refresh rotation is serialized by a lock.** A refresh takes a cache lock on the credential
  (`credentials:oauth2-refresh:{tenant}:{credential}`, 30 s, waited for 5 s), reads the grant again and refreshes
  only if it is still due; a busy lock is `credential_unavailable`, never a refresh without it. `invalid_grant`,
  `invalid_client` or `unauthorized_client` mark the grant `reconnect_needed` with its failure code (shown in the
  console) and a warning log; other failures change nothing. No audit entry is written for it: it is a status
  change made by a call or a job, which `adr-20261111-audit-log-explicit-recording` keeps out of the audit trail
  (the design's `credential.reconnect_needed` entry was dropped in review for that reason; adding it needs an
  amendment of that ADR).
- **Lazy refresh plus a daily keep-alive.** A call refreshes an access token within 60 s of its end; the command
  `credentials:refresh-oauth2-grants` (daily 02:30, `withoutOverlapping`) refreshes connected grants idle for
  7 days or whose refresh token ends within 3 days, in active tenants that are not stopped.
- **Foundation does not change.** The type implements the Foundation interface; two Core interfaces carry what is
  Core's: `RejectedTokenAwareInterface` (a 401 from the target tells the type to stop using its token, replacing
  `HttpTransport`'s comparison with one type id) and `ValidatesOwnSettingsInterface` (the authorization URL must be
  https but need not be on an allowed host, since the browser goes there and no secret is sent; the extra
  authorization parameters may not set the ones the connection sets).
- **A change to the client drops the grant.** `CredentialWriter::update` deletes it in the same transaction when a
  setting of the client (authorization URL, token URL, client id, scope, extra parameters) or the secret changes,
  and records `grant_dropped` in the `credential.updated` entry. Connecting and disconnecting are audited as
  `credential.connected` and `credential.disconnected`, without a value.

Residual risks: Redis unavailable means no refresh and `credential_unavailable` until it is back; the code passes
through the callback URL and may reach a web server or proxy access log (single use, bound to PKCE); a renamed
tenant slug changes the callback URL, so the redirect URI must be updated at the provider and the credential
reconnected; `SESSION_SAME_SITE=strict` keeps the session cookie off the provider's redirect and breaks the
callback; tokens are not revoked at the provider on disconnect.

## Amendment: hardening (task `flow-credentials-call-hardening`, 2026-11-25)

The owner approved the design at the human gate ("I accept your decisions": every recommended option). It closes
review findings of the store and the OAuth2 work.

- **A credential is sent only over https.** `HttpTransport` refuses a target whose scheme is not `https` before the
  host check, with `credential_insecure_target` (metadata `{host}`), no connection and no token request; the call
  node's `validate()` refuses a literal `http://` target with a credential at publish (`call_credential_insecure_target`).
  A templated target is caught only at run time. A live flow that sent a credential over `http://` now fails visibly.
- **A stored secret never reaches a new host unasked.** `CredentialWriter::update` treats the allowed hosts as widened
  when the new list has a pattern the old one did not cover (`HostMatcher::covers`: an exact host covered by a match;
  `*.d` only by `*.d` or a wildcard over a parent of `d`), and treats a token URL moved to another host the same way.
  Then every secret field must be entered again (`credential_validation.secret_reenter_for_new_host`), the consent
  grant of an `oauth2_authorization_code` credential is dropped (its tokens are a person's, which re-entering the
  client secret does not protect), and `credential.updated` records `hosts_widened`. Narrowing needs neither. The
  rejected alternative was to accept the risk: someone allowed to edit a credential but never shown its key could
  otherwise add their own host and read the key in that server's log.
- **Authorizing stays inside the call's window.** A Core interface `AuthorizesWithinDeadlineInterface`
  (`authorizeWithin($secret, $seconds)`), implemented by both OAuth2 types, takes the call's timeout: the token request
  uses the smaller of it and its own 10 s (under 1 s left sends nothing, `credential_unavailable`), and the
  authorization-code refresh waits for its lock at most what is left. Foundation does not change; the keep-alive
  command and the console pass no limit.
- **A 401 drops one key.** The client-credentials type deletes the cached token of the credential's version
  (`OAuth2TokenCache::forgetVersion`), not every version by a keyspace scan; the pattern purge stays for an edit, a
  delete and the tenant forgetter. The purge itself is `App\Domains\Shared\Infrastructure\RedisKeyPurger` (scoped),
  shared with Flow's ledger forgetter.
- **An exception quoting a secret leaves the transport masked.** Any exception the HTTP client throws on a call with a
  credential, other than the ones already turned into results, is thrown again as `CredentialMaskedException` with the
  secrets replaced and no previous exception when its message quotes one. The node and the builder's test call resolve
  a credential through one `CallCredentialResolver`, so their codes cannot drift.
- **Audit and unknown types.** `credential.updated` marks each changed setting as `settings.<key>`; an update of a
  credential whose type is no longer registered is refused (`type_unknown`) instead of overwriting it with nothing.

## Alternatives

- The transport resolves the credential by id itself. Rejected: the transport is a process-wide singleton, and
  a tenant-bound store inside it is what worker safety forbids.
- The handler puts the secret into `parameters` like the inline `auth.bearer`. Rejected: it becomes node
  input, so traces and logs would hold it.
- A `{{credentials.x}}` template namespace. Rejected: the author could place the value in a body or a
  variable, and masking would guarantee nothing.
- Allowed hosts optional, empty meaning any host. Rejected as the default (D-1).
- Follow redirects inside the allowed hosts. Rejected for now: more middleware for a rare case; it can be
  added without breaking anything.
- Mask in every sink (logger, ledger, persister). Rejected: sinks multiply, and one forgotten is a leak.
- The code inside Flow (`app/Domains/Flow/Credentials`) or Tenancy. Rejected: Solutions and connectors are not
  Flow, and Tenancy takes no outward dependencies.
- The OAuth2 token in a database column. Rejected for client credentials: a write on the hot path and races
  between workers, for a token that can always be fetched again. An authorization code grant is stored in the
  database all the same (see the amendment below): its refresh token comes from a person's consent and cannot be
  fetched again.
- `ManageCredentials` / `UseCredentials` now. Rejected: a separate roadmap item with a data migration for
  existing tenants.

## Consequences

- A credential is useless against a host outside its list; changing the host means editing the credential.
- Deleting a credential a flow uses breaks that flow: its `call` takes the error handle with
  `credential_not_found`. The screen warns; a "used in" list is later work.
- Monitoring outside Core is not covered: Telescope's client-request watcher and Sentry's HTTP breadcrumbs can
  record the URI with a query key (`api_key_query`) and custom key headers. A follow-up in `fapost/monitoring`
  must keep requests that carry a credential out of them.
- Until the permissions item, any holder of `ManageSettings` could create, replace and delete credentials, and
  any `ManageFlowDefinitions` holder could bind one to a node (closed by `flow-automation-permissions`, see D-3).
- Rotating `APP_KEY` without `APP_PREVIOUS_KEYS` makes every credential unreadable.
