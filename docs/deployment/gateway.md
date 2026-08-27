# Webhook Gateway

An optional Go service that accepts provider webhooks, verifies them and queues
them, taking that work off PHP.

**It is optional and the application is complete without it.** Without the
gateway, webhooks go to the Laravel route exactly as they always have. Deploy it
when webhook volume becomes a bottleneck, not because it exists.

## What it does

```
provider ──▶ gateway ──▶ Redis queue ──▶ Horizon ──▶ flow execution
                │
                └──▶ application ingress   (anything it cannot decide itself)
```

Per delivery: rate-limit by channel, read the routing entry from Redis, verify
the signature, claim an idempotency key, publish the job. About a millisecond,
no PHP process involved.

The second arrow matters more than the first. **Redis is a cache for the gateway,
never the source of truth.** On a cache miss, an unreadable Redis, an unknown
platform or a spec it cannot execute, it forwards the request to the application,
which resolves the channel from its database and repopulates the cache. A cold or
broken Redis costs throughput, not deliveries.

## How verification works without platform code

The gateway knows nothing about Telegram. Each channel adapter publishes an
**ingress spec** — a small declaration of how that platform's webhook is signed
and deduplicated:

```json
{"v":1,"scheme":"header_equals","parameter":"x-telegram-bot-api-secret-token",
 "prefix":"","idempotency":"tg:{channel}:{body.update_id}"}
```

Adding a channel therefore stays a pure PHP change and needs no gateway release.
A platform whose verification is too complex to express declaratively simply does
not publish a spec, and its webhooks stay on the PHP path.

Both implementations are held to
[`contracts/ingress/golden.json`](../../contracts/ingress/golden.json) — 40 cases
executed by both the PHP and the Go test suites, so the two cannot drift apart.

## Deploying

### With Docker Compose

The gateway sits behind a profile, so it starts only when asked:

```bash
docker compose -f docker/compose.yaml --env-file .env --profile gateway up -d
```

### Standalone

```bash
cd gateway
make build                    # or: make dist   for release binaries
sudo install -m 0755 bin/gateway /usr/local/bin/gateway
```

The binary is static and has no runtime dependencies. `make dist`
cross-compiles for linux/amd64, linux/arm64 and darwin/arm64 from any machine.

Then generate the supervisor files:

```bash
php artisan gateway:install
```

It asks how the gateway will run, where providers will deliver, and where to log,
then writes the settings into `.env` and generates a systemd unit, a logrotate
config or a compose fragment into `gateway/dist/` for you to review and install.
Nothing is written outside the project and no service is restarted.

## Configuration

Set by `gateway:install`, or by hand:

| Variable | Meaning |
|----------|---------|
| `WEBHOOK_INGRESS_DRIVER` | `laravel` or `gateway` — where **newly registered** channels point |
| `WEBHOOK_GATEWAY_URL` | Public URL providers deliver to |
| `GATEWAY_ADDR` | Listen address, e.g. `127.0.0.1:8080` |
| `GATEWAY_UPSTREAM_URL` | The application's own ingress, for the fallback |
| `GATEWAY_TRUSTED_PROXIES` | Addresses whose `X-Forwarded-For` may be believed |
| `GATEWAY_RATE_PER_SECOND` | Per-channel limit; `0` disables |
| `GATEWAY_LOG_DESTINATION` | `stdout` or `file` |

Redis settings are **not** duplicated: the gateway reads the same `REDIS_*`
variables as the application. `REDIS_PREFIX` in particular must match, or every
lookup misses and the gateway proxies everything while appearing healthy.

## Migrating existing channels

Switching the driver only affects channels registered from that moment on.
Existing ones keep the URL their provider stored, and **both paths stay valid**,
so there is no cutover and no window to race against.

```bash
php artisan ops:ingress-migrate              # report what is still behind
php artisan ops:ingress-migrate --apply      # queue re-registration, in batches
```

Re-registration reuses the same public hash and secret — only the host changes —
so the provider sees no interruption. Batching exists because `setWebhook` is
rate-limited; re-run until the report is empty.

Rolling back is the same command after setting the driver back to `laravel`.

## Order of operations

1. Publish the specs — `php artisan ops:ingress-specs-publish`
2. Deploy the gateway and put TLS in front of it
3. Set `WEBHOOK_INGRESS_DRIVER=gateway` and `WEBHOOK_GATEWAY_URL`
4. Migrate existing channels at your own pace

Step 1 comes first for a reason: without published specs the gateway has nothing
to verify against and proxies every delivery straight back to the application.
`ops:webhook-warmup` republishes them too, so a single command restores all
ingress state after a Redis flush.

## Verifying

```bash
php artisan gateway:doctor
```

Checks the driver, that Redis is reachable, that the key prefix is what the
gateway will resolve, that published specs match the adapters, that the gateway's
health endpoint answers, and how many channels are still on the old URL.

## Operating

**TLS.** The gateway speaks plain HTTP; terminate in front with Traefik or Caddy.
The terminator must not modify the request body — signatures cover the exact
bytes the provider sent.

**Logs.** JSON on stdout by default. In file mode, `SIGHUP` reopens the file, so
`logrotate` handles rotation; the same signal also drops cached ingress specs, so
a republish takes effect without a restart.

**Shutdown.** `SIGTERM` drains in-flight requests. This matters more than usual:
a delivery already answered with 200 will not be sent again, so cutting those
requests off loses messages outright.

**What is never logged.** The webhook hash is the credential for a channel, so
only a fingerprint of it appears. Request bodies and signature headers never do.

## Related

- [Deployment overview](./README.md)
- [Docker Compose](./docker-compose.md)
- [Long-running services](./services.md)
