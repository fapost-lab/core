# Long-running Services

An installation is not complete when the web server answers. Two background
processes must run continuously, and their absence is invisible from the browser:
the admin panel works, channels look connected, and no incoming message is ever
answered.

This document describes each service, why it exists, and how to supervise it.

## The services

| Service   | Command                                   | Required | Fails as                                    |
|-----------|-------------------------------------------|----------|---------------------------------------------|
| Horizon   | `php artisan horizon`                     | Yes      | Messages queue up and nothing processes them |
| Scheduler | `php artisan schedule:run` every minute   | Yes      | Scheduled triggers and cleanup never fire   |
| Gateway   | `gateway --env .env`                       | No       | Webhooks stay on the PHP path — a supported mode |

## Horizon

Every inbound message, every outbound send and every flow execution runs in a
queued job. Horizon supervises the workers that consume them.

The queues are separated by purpose, and `config/horizon.php` groups them into
supervisors by priority:

| Supervisor | Queues                                                     | Priority |
|------------|------------------------------------------------------------|----------|
| `high`     | `flow.execution`, `messaging.transactional`                 | Inbound processing and live replies |
| `medium`   | `sync.external`, `scheduled.triggers`                       | Integrations and scheduled fan-out |
| `low`      | `messaging.broadcast`, `messaging.system`, `messaging.logging` | Bulk sends and bookkeeping, niced down |
| `default`  | `default`                                                   | Anything unclassified |

Two things about this matter operationally:

**Do not consolidate the queues into one worker pool.** A broadcast to fifty
thousand contacts would then sit in front of a live conversation, and the person
waiting for an answer would get one when the broadcast finishes.

**Horizon needs `pcntl` and `posix`.** Without them it fails to start, and
because the web tier is unaffected the installation looks healthy.

Restart Horizon on every deploy. Workers are long-lived PHP processes holding the
previous release in memory:

```bash
php artisan horizon:terminate
```

This lets in-flight jobs finish and exits; the supervisor restarts the process
with the new code. Killing workers outright drops jobs that were mid-flight.

## Scheduler

Laravel's scheduler must be invoked once a minute. It drives scheduled triggers,
flow log partition creation and cleanup tasks.

Traditional cron:

```cron
* * * * * cd /var/www/fapost && php artisan schedule:run >> /dev/null 2>&1
```

Under systemd, a timer is preferable — it logs to the journal and does not depend
on a mail-capable cron daemon:

```ini
# /etc/systemd/system/fapost-scheduler.service
[Unit]
Description=FAPost scheduler tick

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/fapost
ExecStart=/usr/bin/php artisan schedule:run
```

```ini
# /etc/systemd/system/fapost-scheduler.timer
[Unit]
Description=Run the FAPost scheduler every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=1s
Persistent=false

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl enable --now fapost-scheduler.timer
```

Run exactly one scheduler across the whole installation. Two hosts both ticking
means every scheduled trigger fires twice.

## Horizon under systemd

```ini
# /etc/systemd/system/fapost-horizon.service
[Unit]
Description=FAPost queue workers (Horizon)
After=network-online.target postgresql.service redis.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/fapost
ExecStart=/usr/bin/php artisan horizon

# horizon:terminate lets running jobs finish, so a reload is graceful.
ExecReload=/usr/bin/php artisan horizon:terminate

Restart=always
RestartSec=3

# Jobs wait on external APIs, so give them room to finish before the kill.
KillSignal=SIGTERM
TimeoutStopSec=120

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now fapost-horizon
```

`Restart=always` matters more than usual: Horizon terminates itself when a worker
exceeds its memory limit, and expects a supervisor to bring it back.

## Verifying

```bash
# Workers are running and consuming
php artisan horizon:status

# Nothing is stuck at the front of a queue
php artisan queue:monitor flow.execution,messaging.transactional

# The scheduler ticked recently
systemctl status fapost-scheduler.timer
```

A queue depth that only grows is the signature of Horizon not running, or running
without the queues configured.

## Related

- [Deployment overview](./README.md)
- [Docker Compose](./docker-compose.md) — where these services are containers
- [Bare metal](./bare-metal.md)
- [Webhook gateway](./gateway.md) — the optional third service
