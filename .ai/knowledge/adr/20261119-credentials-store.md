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
summary: Why a tenant's saved credentials live in their own Credentials domain, are bound to a mandatory list of allowed hosts, reach a call only as a masked, never-redirected, Foundation-contract secret
reviewed_at: 2026-11-20
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
- The OAuth2 token in a database column. Rejected: a write on the hot path and races between workers.
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
