# Deployment

How to run FAPost Core on your own infrastructure.

These documents are the source of truth for deployment. Every step is written so
it can be followed by hand; the scripts under `deploy/` automate exactly what is
described here and nothing more. If a script and a document disagree, the
document is right and the script is a bug.

## Choosing a method

| Method | Best when | Trade-off |
|--------|-----------|-----------|
| [Docker Compose](./docker-compose.md) | You want the shortest path, or your host cannot easily provide PHP 8.4 | The whole stack is containers; you manage volumes and images rather than packages |
| [Bare metal](./bare-metal.md) | You already run PHP applications, have package and process management in place, or cannot use containers | You provide PHP 8.4 and its extensions yourself, usually via a third-party repository |

Both are supported and neither is a lesser path. They differ in who provides the
runtime, not in how the application is configured: after either one you run the
same `php artisan install`, and the same services must be running.

Read [requirements.md](./requirements.md) first regardless of method — it lists
what the application needs from a host, including the two things most often
missed: the `pcntl`/`posix` extensions and the `CREATE` privilege on the database.

## The shape of an installation

Whatever the method, a working install has the same parts:

```
             ┌──────────────┐
   HTTPS ───▶│  Web server  │──▶ PHP-FPM ──▶ admin panel, builder, webhook ingress
             └──────────────┘
                                              │
                                              ▼
             ┌──────────────┐          ┌────────────┐
             │  PostgreSQL  │◀────────▶│  Horizon   │──▶ flow execution, messaging
             └──────────────┘          └────────────┘
                    ▲                        ▲
                    │                        │
             ┌──────────────┐          ┌────────────┐
             │  Scheduler   │          │   Redis    │
             └──────────────┘          └────────────┘
                                              ▲
                                              │
   HTTPS ───▶ [ Gateway ] ──────────────────── ┘   (optional, see gateway.md)
```

The pieces people forget are Horizon and the scheduler. Without them the site
loads, the admin panel works, and nothing actually happens: messages arrive and
are never answered. See [services.md](./services.md).

## Order of operations

1. Prepare the host — [requirements.md](./requirements.md)
2. Install the runtime — [Docker Compose](./docker-compose.md) or [bare metal](./bare-metal.md)
3. Configure the application — `php artisan install`, covered in both guides
4. Start the long-lived services — [services.md](./services.md)
5. Optionally put the Go gateway in front of webhooks — [gateway.md](./gateway.md)
6. Verify — each guide ends with the checks worth running

## Upgrades

See [upgrading.md](./upgrading.md). The short version: migrations are additive,
tenant schemas migrate separately from the landlord schema, and queue workers
must be restarted after a deploy because they hold the old code in memory.

## Not covered here

- Local development — see [getting-started.md](../platform/getting-started.md)
- Extension development — see the [developer portal](../developers/index.html)
- High availability and multi-node setups. Nothing prevents them, but nothing has
  been tested or documented, so treat it as unexplored rather than supported.
