---
id: feature-load-test-harness
type: feature
status: active
domains:
  - flow
  - tenancy
paths:
  - "app/Console/Commands/Ops/LoadTest/**"
  - "tools/loadtest/**"
  - "app/Domains/Channels/Telegram/TelegramBotApiClient*.php"
  - app/Domains/Tenancy/Services/TenantDecommissioner.php
summary: Throwaway-tenant load test of the flow pipeline against a local Telegram stub; tells lock-contention drops from real leaks
---
# Load-test harness

## What

`make loadtest` (or the four `loadtest:*` artisan commands directly) provisions several
throwaway tenants, each with one assistant/Telegram channel/published flow, drives synthetic
Telegram webhook traffic against their contacts through the real HTTP → queue → flow-engine
pipeline, and checks the result for state leaks. `tools/loadtest/telegram-stub.php` stands in for
the real Telegram Bot API so no traffic ever reaches `api.telegram.org`
(`services.telegram.api_base_url`, see `TelegramBotApiClientFactory`).

## Why

Closes the last `docs/platform/ROADMAP.md` "Production Ready" criterion: concurrent sessions on
Horizon-style workers must not leak state across tenants/contacts/sessions, and the session lock
must hold up under real contention. That can only be shown by running the real pipeline — HTTP
ingress, Redis dedup/locks, PostgreSQL tenant schemas, queue workers — not by a unit test.

The seeded flow is fixed and deliberately trivial (prompt → `input` → echo → `end`): every
contact's expected value (`LT-<TENANT_SLUG>-<INDEX>-<RAND>`, see `LoadTestCodeGenerator`) carries
its own tenant and contact identity in the text itself, so a leak is a plain string comparison —
`LoadTestSessionVerifier` never has to infer ownership from timing or side channels.

## Constraints

- A contact's message being dropped under lock contention
  (`RoutingOutcome::dropped('lock_timeout')`, `DropPolicy`'s busy reply) is an **expected** outcome
  of real concurrency, not a leak: `loadtest:verify` counts it separately and only fails on it past
  `--max-dropped` (default 5%). A **leak** is a contact ending up with a value that provably belongs
  to a different contact or tenant — that always fails, at any count.
- `failed_jobs` is a table shared with everything else that ever ran against the dev database.
  `loadtest:verify` scopes its count to `failed_at >= state.created_at`; an unscoped count picks up
  unrelated pre-existing failures and produces a false failure.
- `loadtest:seed` refuses to run unless `services.telegram.api_base_url` is already pointed away
  from the real API — creating an active Telegram channel synchronously dispatches
  `SyncChannelWebhookJob::dispatchSync()`, which calls the real `setWebhook`/`getMe` otherwise.
- The stub is started with `PHP_CLI_SERVER_WORKERS` for concurrency, which preforks worker
  processes that outlive the master's own pid. Stopping it by the master's `$!` pid leaves the
  workers running and bound to the port; the Makefile kills it with `pkill -f` against the full
  command line instead.
- All four commands run only in the `local`, `loadtest`, `staging` and `testing` environments —
  an allow-list, so a differently named environment pointed at a real database is refused too.
- Run state (which tenants/contacts a run created) lives in `storage/app/loadtest/state.json` —
  one active run at a time — and is saved after every seeded tenant, so a seed that fails half-way
  stays cleanable. `loadtest:clean` removes exactly what that file names (plus, with `--all`, any
  `loadtest-*` tenant — what `make loadtest` uses after a failed seed, since a tenant that broke
  inside provisioning is not in the file yet). It never prefix-scans Redis: the dedup/rate-limit/
  idempotency keys the run touches all carry their own TTL.
- Tenant removal goes through Tenancy's `TenantDecommissioner` (registry entries, then schema,
  then landlord row) and `TenantRepositoryInterface`; the harness never opens the `landlord`
  connection itself.
- `make -n loadtest` is not a dry run: GNU Make still executes recipe lines that invoke `$(MAKE)`.

## Decisions and rejected alternatives

Considered and rejected (recorded here rather than as an ADR — this is test tooling, not a
runtime architectural change):

- **k6/Locust/JMeter** — an external tool in the dev workflow for what `Http::pool()` already
  covers at this scale (tens to low hundreds of concurrent sessions).
- **Dispatching `IncomingMessageJob` directly, skipping HTTP** — would not exercise the webhook
  controller, its Redis dedup, or the webhook registry, which also hold state that can leak.
- **A dedicated `loadtest` channel/adapter instead of a real Telegram channel pointed at a
  stub** — would exercise a different code path than production traffic
  (`TelegramSender`, keyboards, rate limiting).
