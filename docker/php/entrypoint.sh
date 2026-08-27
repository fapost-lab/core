#!/bin/bash
# Container entrypoint for every PHP role.
#
# Does the minimum that must happen inside the container and nothing that
# belongs to an operator: it never migrates, never provisions and never edits
# .env. Automatic migrations on boot are convenient until three replicas start
# at once and race each other through the same schema change.
set -euo pipefail

readonly ROLE="${1:-php-fpm}"

wait_for() {
    local label="$1" host="$2" port="$3" attempts=30

    until nc -z "$host" "$port" 2>/dev/null; do
        attempts=$((attempts - 1))
        if [ "$attempts" -le 0 ]; then
            echo "entrypoint: ${label} at ${host}:${port} is not reachable" >&2
            return 1
        fi
        sleep 1
    done
}

# Compose health checks already gate startup, but a dependency can drop out
# later and restart; waiting here turns that into a delay rather than a crash
# loop that pollutes the logs.
if [ -n "${DB_HOST:-}" ]; then
    wait_for "PostgreSQL" "${DB_HOST}" "${DB_PORT:-5432}"
fi

if [ -n "${REDIS_HOST:-}" ]; then
    wait_for "Redis" "${REDIS_HOST}" "${REDIS_PORT:-6379}"
fi

# Config and route caches are built at boot rather than baked into the image:
# they capture environment values, and an image is promoted across environments
# whose values differ.
#
# Only for the long-running roles. A one-off `docker exec ... php artisan tinker`
# or an ad-hoc command has no business rewriting the cache — and doing so from a
# container started without the full environment would cache the wrong values
# for everything else.
warm_caches() {
    [ "${APP_ENV:-production}" = "production" ] || return 0
    [ "${SKIP_CACHE_WARMUP:-0}" != "1" ] || return 0

    php artisan config:cache --no-interaction || true
    php artisan route:cache --no-interaction || true
    php artisan event:cache --no-interaction || true
}

case "${ROLE}" in
    horizon)
        warm_caches
        # Horizon terminates itself when a worker exceeds its memory limit and
        # relies on the supervisor to bring it back; compose restarts the container.
        exec php artisan horizon
        ;;
    scheduler)
        warm_caches
        # A minute tick without cron. Sleeping to the next minute boundary keeps
        # drift from accumulating over long uptimes.
        while true; do
            php artisan schedule:run --no-interaction || true
            sleep $((60 - $(date +%S)))
        done
        ;;
    php-fpm)
        warm_caches
        exec php-fpm
        ;;
    *)
        exec "$@"
        ;;
esac
