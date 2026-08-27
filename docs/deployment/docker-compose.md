# Deployment: Docker Compose

Runs the whole stack in containers. The host needs Docker and nothing else — no
PHP 8.4, no Composer, no Node.

Everything below has been executed against this compose file; the troubleshooting
section lists failures actually encountered rather than ones imagined.

## What you get

Six services, defined in [`docker/compose.yaml`](../../docker/compose.yaml):

| Service     | Image                            | Role |
|-------------|----------------------------------|------|
| `web`       | `ghcr.io/fapost-lab/web`         | nginx with the compiled front-end |
| `app`       | `ghcr.io/fapost-lab/core`        | PHP-FPM |
| `horizon`   | same image, different command    | queue workers |
| `scheduler` | same image, different command    | one-minute tick |
| `postgres`  | `postgres:16-alpine`             | database |
| `redis`     | `redis:7-alpine`                 | queues, cache, webhook registry |

Two more sit behind Compose profiles: `caddy` for TLS with automatic
certificates (`--profile tls`, see [step 6](#6-tls)), and the webhook gateway
(`--profile gateway`, see [gateway.md](./gateway.md)). Both are optional — the
first only if you do not already terminate TLS elsewhere.

`app`, `horizon` and `scheduler` deliberately share one image: they run identical
code and differ only in the command. Building them separately would let their
dependencies drift, which is how "works on the web tier, fails in the worker"
happens.

## Requirements

- Docker Engine 24+ with the Compose plugin (`docker compose`, not `docker-compose`)
- 4 GB RAM and 10 GB disk to start with

Read [requirements.md](./requirements.md) for what the application itself needs;
the images already satisfy the runtime and extension list.

## 1. Get the files

```bash
git clone https://github.com/fapost-lab/fapost-core.git
cd fapost-core
cp .env.example .env
```

Only `docker/` and `.env` are needed at runtime — images come from the registry.
The clone is the simplest way to obtain them and gives you the docs besides.

## 2. Configure `.env`

Compose reads this file twice, in two different ways, and the distinction matters:

- **Interpolation** — `${DB_PASSWORD}` inside `compose.yaml` is resolved from the
  file named by `--env-file`, which defaults to a `.env` **next to the compose
  file**. That is why every command below passes `--env-file ../.env` explicitly.
- **Container environment** — `env_file:` hands the whole file to the containers.
  It defaults to `../.env` and can be pointed elsewhere with `ENV_FILE`.

The values that must be right before the first start:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=                      # generated in step 3
APP_URL=https://fapost.example.com

DB_CONNECTION=pgsql
DB_DATABASE=fapost
DB_USERNAME=fapost
DB_PASSWORD=<a real password>  # must not be empty

REDIS_PASSWORD=<a real password>
REDIS_PREFIX=fapost:

QUEUE_CONNECTION=redis
```

`DB_HOST` and `REDIS_HOST` are overridden by Compose to the service names, so
whatever they say is ignored inside containers.

**`APP_ENV=production` is not cosmetic.** The images are built with `--no-dev`,
and development tooling registered for the `local` environment is absent from
them. Running a production image with `APP_ENV=local` used to fail at boot with a
missing Telescope class; a guard now prevents that, but the setting is still
wrong for anything but development.

**Empty passwords fail fast, by design.** `postgres` and `redis` refuse to start
without one, so Compose validates them up front rather than letting the stack
half-start:

```
required variable DB_PASSWORD is missing a value
```

## 3. Start the stack

```bash
docker compose -f docker/compose.yaml --env-file .env up -d
```

Or from `docker/` with the shorthand, which carries the flags for you:

```bash
cd docker && make up
```

First start pulls the images and initialises the database volume. Watch it settle:

```bash
docker compose -f docker/compose.yaml --env-file .env ps
```

`postgres` and `redis` should reach `healthy` before `app` starts — that ordering
is enforced by health checks, not by sleeps.

## 4. Install

```bash
docker compose -f docker/compose.yaml --env-file .env exec app php artisan install
```

The wizard generates the application key if there is none, verifies the database
and Redis connections, applies the landlord migrations, and provisions the first
tenant with its administrator. Steps that are already done are skipped, so it is
safe to re-run after fixing something.

The containers hold a cached config from startup, so restart them once the
wizard has written new values:

```bash
docker compose -f docker/compose.yaml --env-file .env up -d --force-recreate app horizon scheduler
```

Until a tenant exists the site answers **500** — the request cannot be resolved to
a tenant. That is expected before this step, not a fault.

## 5. Verify

```bash
C="docker compose -f docker/compose.yaml --env-file .env"

$C ps                                  # every service up, postgres/redis healthy
curl -I http://localhost:8000/         # 200 once a tenant exists
curl -I http://localhost:8000/build/manifest.json   # 200 — assets are being served
$C exec app php artisan horizon:status # workers running
$C logs -f horizon
```

A growing queue with no movement means Horizon is not consuming — see
[services.md](./services.md).

## 6. TLS

TLS is not optional in practice: Telegram refuses `setWebhook` without a
certificate it trusts, so channels do not work over plain HTTP.

There are two supported ways to get it.

### Included: Caddy with automatic certificates

Enable the `tls` profile and Caddy obtains and renews Let's Encrypt certificates
on its own — no certbot, no renewal cron, no reload hooks:

```dotenv
APP_DOMAIN=fapost.example.com
ACME_EMAIL=ops@example.com
GATEWAY_DOMAIN=webhook.example.com   # only if you deploy the gateway

# Keep the plain-HTTP ports off the public interface, or they are a way around
# TLS entirely.
HTTP_BIND=127.0.0.1
GATEWAY_BIND=127.0.0.1
```

```bash
docker compose -f docker/compose.yaml --env-file .env --profile tls up -d
```

Both domains must already resolve to this host — Caddy proves control over them
by answering an HTTP challenge on port 80, so DNS comes first.

While testing, uncomment `acme_ca` in [`docker/caddy/Caddyfile`](../../docker/caddy/Caddyfile)
to use the staging CA. The production one rate-limits failed attempts per domain,
and spending that budget on a typo in a DNS record locks you out for a week.

### Or terminate it yourself

If you already run Traefik, nginx or a cloud load balancer, leave the profile off
and point it at the `web` service on `HTTP_PORT`.

Whatever you use **must forward the request body unmodified**. Webhook signatures
are computed over the exact bytes the provider sent, so any middleware that
rewrites, decompresses or re-encodes the body breaks verification for every
channel. Compressing *responses* is fine.

Set `GATEWAY_TRUSTED_PROXIES` to the address of the terminator. The gateway
ignores `X-Forwarded-For` from anyone not listed — otherwise a caller could forge
a client address and walk straight past the rate limit.

## Building your own images

Solutions and Plugins are Composer packages, so they have to be inside the
application image.

Building is an explicit choice, made by merging an overlay:

```bash
docker compose -f docker/compose.yaml -f docker/compose.build.yaml --env-file .env build
docker compose -f docker/compose.yaml -f docker/compose.build.yaml --env-file .env up -d
```

Or `make build && make up-built` from `docker/`.

The build definitions live in a separate file deliberately. Compose treats a
service that declares a `build:` as one it should build: with both `image:` and
`build:` present it compiles locally and never contacts the registry — even with
`pull_policy: always`. Keeping them apart is what makes pulling the default.

Both images come from [`docker/Dockerfile`](../../docker/Dockerfile) via targets
`core` and `web`. They share one Dockerfile because the Filament theme imports
CSS out of `vendor/`, so the front-end cannot be compiled without the Composer
install — splitting them would mean installing dependencies twice, with two
results that could differ.

Note that `packages/` is excluded from the build context. Those are separate git
checkouts that `composer dev:link` symlinks over `vendor/` during development;
inside an image the linker would replace released packages with whatever happened
to be checked out.

## Upgrading

```bash
C="docker compose -f docker/compose.yaml --env-file .env"

$C pull
$C up -d
$C exec app php artisan migrate --database=landlord --path=database/migrations/landlord --force
$C exec app php artisan ops:tenants-migrate
$C exec app php artisan horizon:terminate
```

`horizon:terminate` lets running jobs finish and exits; Compose restarts the
container with the new code. Workers hold the previous release in memory until
this happens.

See [upgrading.md](./upgrading.md) for the details, including tenant migrations.

## Troubleshooting

**Containers keep the old image after a rebuild.** Compose compares tags, not
content, so rebuilding under the same tag changes nothing on its own:

```bash
docker compose -f docker/compose.yaml --env-file .env up -d --force-recreate
```

**`horizon` restarts in a loop.** Check its logs first: it fails on start rather
than degrading. A boot error affecting the whole application shows up here first
because the web tier can still serve cached pages.

```bash
docker compose -f docker/compose.yaml --env-file .env logs horizon --tail 30
```

**The site returns 500 right after installation.** Almost always no tenant yet —
run `platform:install`. Confirm with:

```bash
docker compose -f docker/compose.yaml --env-file .env exec app \
  php artisan tinker --execute='echo App\Domains\Tenancy\Models\Tenant::query()->count();'
```

**Changes to `.env` have no effect.** Config is cached at container start for
`APP_ENV=production`. Recreate the containers, or set `SKIP_CACHE_WARMUP=1` while
debugging.

**Running a second environment from the same files.** Point `ENV_FILE` at another
file and use a separate project name, so volumes and containers do not collide:

```bash
ENV_FILE=../.env.staging docker compose -p fapost-staging \
  -f docker/compose.yaml --env-file ../.env.staging up -d
```

**Assets 404 while pages load.** The web image carries the compiled front-end, so
this means `web` and `app` are on different versions. Pull both and recreate.

## Related

- [Deployment overview](./README.md)
- [Requirements](./requirements.md)
- [Long-running services](./services.md)
- [Webhook gateway](./gateway.md)
- [Bare metal](./bare-metal.md)
