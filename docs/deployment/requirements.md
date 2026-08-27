# Deployment Requirements

What a host must provide to run FaPost Core. Every deployment method in this
section satisfies these requirements differently, but none of them removes one.

Verified against `composer.json`, `config/`, and the packages actually installed —
not against what a typical Laravel application usually needs.

## Runtime

| Component  | Version | Why this floor                                                        |
|------------|---------|-----------------------------------------------------------------------|
| PHP        | 8.4+    | `composer.json` requires `^8.4`; the code uses typed class constants and property hooks |
| PostgreSQL | 15+     | Tenant isolation is schema-per-tenant, and the installer creates schemas at runtime |
| Redis      | 6+      | Queues, cache, locks and the webhook routing registry                 |
| Node.js    | 20+     | Build-time only — compiles the Vue flow builder through Vite          |
| Composer   | 2.x     | Build-time only                                                       |
| Go         | 1.27+   | Optional, and build-time only — needed solely for the webhook gateway |

PHP 8.4 is the requirement most likely to bite. Debian 12 ships 8.2 and Ubuntu
24.04 ships 8.3, so a bare-metal install needs a third-party repository
([sury.org](https://deb.sury.org/) on Debian/Ubuntu, [Remi](https://rpms.remirepo.net/)
on RHEL-family). Container deployments avoid the question entirely.

## PHP extensions

Required — the application will not boot without these:

- `pdo`, `pdo_pgsql` — declared in `composer.json`
- `redis` — `config/database.php` defaults `REDIS_CLIENT` to `phpredis`. Using
  `predis` instead is possible but untested here; if you switch, set
  `REDIS_CLIENT=predis` and add the Composer package.
- `mbstring`, `intl`, `bcmath` — Laravel 12 and Filament
- `gd` — image handling in the admin panel
- `zip` — Composer and export features
- `json`, `openssl`, `tokenizer`, `xml`, `ctype`, `fileinfo`, `curl` — Laravel baseline

Required for queue workers, which is where all flow execution happens:

- `pcntl`, `posix` — Horizon needs both to supervise workers. Missing them is a
  quiet failure mode: the application serves pages normally while no queued job
  ever runs, so incoming messages simply never get a reply.

## Database privileges

The application creates and migrates a PostgreSQL **schema per tenant** at
runtime, so its database user needs more than the usual read/write grants:

```sql
CREATE DATABASE fapost;
CREATE USER fapost WITH PASSWORD '...';
GRANT ALL PRIVILEGES ON DATABASE fapost TO fapost;
-- Required: tenant provisioning issues CREATE SCHEMA
GRANT CREATE ON DATABASE fapost TO fapost;
```

A user without `CREATE` on the database installs cleanly and then fails when the
first tenant is provisioned.

Two logical connections are configured, `landlord` and `tenant`. They may point
at the same PostgreSQL instance and database — the separation is by schema, not
by server — but keeping them separate is supported.

## Processes that must run

A working installation is more than a web server. These are separate long-lived
processes, and the system is silently degraded without them:

| Process        | Purpose                                              | Consequence if absent                    |
|----------------|------------------------------------------------------|------------------------------------------|
| Web server     | Admin panel, builder, webhook ingress                | Nothing is reachable                     |
| Horizon        | Queue workers for every queue                        | Messages arrive but are never processed  |
| Scheduler      | `schedule:run` every minute                          | Scheduled triggers and cleanup never run |
| Gateway        | Optional Go webhook ingress                          | Webhooks stay on the PHP path (fine)     |

Queues are separated by purpose (`flow.execution`, `messaging.transactional`,
`messaging.broadcast`, `messaging.system`, `scheduled.triggers`, `sync.external`),
and Horizon must be configured to consume all of them.

## Network

- Inbound HTTPS for the admin panel and the builder.
- Inbound HTTPS for webhook delivery, with a certificate that public providers
  accept. Telegram rejects `setWebhook` on an invalid certificate, so a
  self-signed one requires the certificate upload flow instead.
- Outbound HTTPS to messaging providers and any LLM or RAG endpoint in use.

## Resources

There is no benchmark to quote yet, so treat these as starting points rather
than sizing guidance:

- 2 vCPU / 4 GB RAM for a small single-tenant install with a handful of channels.
- Queue workers dominate memory: each Horizon worker is a full PHP process, and
  flow execution holds a session while waiting on external calls.
- PostgreSQL grows with conversation history, which is partitioned — see the
  conversation logging documentation before planning retention.

## Related

- [Deployment overview](./README.md) — choosing a method
- [Local development setup](../platform/getting-started.md) — for working on Core itself, not for running it in production
