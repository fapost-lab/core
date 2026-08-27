#!/usr/bin/env bash
#
# FaPost Core — bare-metal bootstrap.
#
# Does only what a shell script must do: verify the runtime, install the
# project's own dependencies, prepare .env, and hand over to `php artisan
# install`. Everything past that point lives in artisan, where it can validate
# input, probe connections and be covered by tests.
#
# It deliberately does NOT install system packages, add repositories, configure a
# web server or touch anything under /etc. Those decisions belong to whoever owns
# the host — the script tells you what is missing and how to get it, then stops.
#
# Usage:
#   ./deploy/install.sh                 check, install dependencies, then configure
#   ./deploy/install.sh --check-only    report readiness and exit
#   ./deploy/install.sh --skip-build    do not compile front-end assets
#
# See docs/deployment/bare-metal.md for the full procedure.

set -euo pipefail

readonly ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly MIN_PHP="8.4"
readonly MIN_NODE="20"

# Extensions the application cannot boot or process work without. pcntl and posix
# are listed because Horizon needs them and their absence is silent: the site
# serves normally while no queued job ever runs.
readonly REQUIRED_EXTENSIONS=(
    pdo pdo_pgsql redis mbstring intl bcmath gd zip
    json openssl tokenizer xml ctype fileinfo curl
    pcntl posix
)

CHECK_ONLY=0
SKIP_BUILD=0
PROBLEMS=0

for arg in "$@"; do
    case "$arg" in
        --check-only) CHECK_ONLY=1 ;;
        --skip-build) SKIP_BUILD=1 ;;
        -h|--help) sed -n '3,20p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'; exit 0 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

# --- output -----------------------------------------------------------------

if [ -t 1 ]; then
    readonly C_OK=$'\033[32m' C_WARN=$'\033[33m' C_ERR=$'\033[31m' C_DIM=$'\033[2m' C_OFF=$'\033[0m'
else
    readonly C_OK='' C_WARN='' C_ERR='' C_DIM='' C_OFF=''
fi

ok()   { printf '  %sok%s   %s\n' "$C_OK" "$C_OFF" "$1"; }
warn() { printf '  %swarn%s %s\n' "$C_WARN" "$C_OFF" "$1"; }
fail() { printf '  %sfail%s %s\n' "$C_ERR" "$C_OFF" "$1"; PROBLEMS=$((PROBLEMS + 1)); }
hint() { printf '       %s%s%s\n' "$C_DIM" "$1" "$C_OFF"; }
head1() { printf '\n%s\n' "$1"; }

# --- checks -----------------------------------------------------------------

# Compares dotted versions without assuming sort -V is available everywhere.
version_at_least() {
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -t. -k1,1n -k2,2n -k3,3n | head -n1)" = "$2" ]
}

check_php() {
    if ! command -v php >/dev/null 2>&1; then
        fail "PHP is not installed (need ${MIN_PHP}+)"
        hint "Debian/Ubuntu: https://deb.sury.org/   RHEL family: https://rpms.remirepo.net/"
        return
    fi

    local version
    version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"

    if version_at_least "$version" "$MIN_PHP"; then
        ok "PHP $version"
    else
        fail "PHP $version is too old (need ${MIN_PHP}+)"
        hint "No mainstream distribution ships ${MIN_PHP} yet; a third-party repository is required."
    fi
}

check_extensions() {
    local missing=()

    # Asked of PHP directly rather than by matching `php -m` output. Parsing that
    # listing depends on the exact behaviour of grep, and a drop-in replacement
    # with slightly different flag semantics reports extensions as missing that
    # are plainly loaded — a false failure that blocks a perfectly good host.
    local report
    report="$(php -r '
        $missing = [];
        foreach (array_slice($argv, 1) as $extension) {
            if (! extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        echo implode(" ", $missing);
    ' "${REQUIRED_EXTENSIONS[@]}" 2>/dev/null)"

    if [ -n "$report" ]; then
        read -r -a missing <<< "$report"
    fi

    if [ ${#missing[@]} -eq 0 ]; then
        ok "PHP extensions (${#REQUIRED_EXTENSIONS[@]} required)"
        return
    fi

    fail "missing PHP extensions: ${missing[*]}"

    for extension in "${missing[@]}"; do
        case "$extension" in
            pcntl|posix)
                hint "$extension: Horizon cannot supervise workers without it — queues would silently never run"
                ;;
            redis)
                hint "redis: the configured client is phpredis (REDIS_CLIENT), usually php-redis or pecl install redis"
                ;;
        esac
    done
}

check_command() {
    local binary="$1" label="$2" advice="${3:-}"

    if command -v "$binary" >/dev/null 2>&1; then
        ok "$label"
    else
        fail "$label is not installed"
        [ -n "$advice" ] && hint "$advice"
    fi
}

check_node() {
    if ! command -v node >/dev/null 2>&1; then
        if [ "$SKIP_BUILD" -eq 1 ]; then
            warn "Node.js is not installed — skipping asset build as requested"
        else
            fail "Node.js is not installed (need ${MIN_NODE}+, build only)"
            hint "Or re-run with --skip-build if assets are already compiled"
        fi
        return
    fi

    local version
    version="$(node -p 'process.versions.node.split(".")[0]')"

    if [ "$version" -ge "$MIN_NODE" ]; then
        ok "Node.js $(node -v)"
    else
        fail "Node.js $(node -v) is too old (need ${MIN_NODE}+)"
    fi
}

check_writable() {
    local path="$ROOT/$1"

    if [ -w "$path" ]; then
        ok "$1 is writable"
    else
        fail "$1 is not writable by $(id -un)"
        hint "chown -R $(id -un) $path"
    fi
}

run_checks() {
    head1 "Runtime"
    check_php
    check_extensions

    head1 "Tooling"
    check_command composer "Composer" "https://getcomposer.org/download/"
    check_node
    check_command psql "psql client" "Needed to administer PostgreSQL; the application itself uses pdo_pgsql"

    head1 "Permissions"
    check_writable storage
    check_writable bootstrap/cache
}

# --- installation -----------------------------------------------------------

install_dependencies() {
    head1 "Dependencies"

    printf '  installing PHP packages\n'
    (cd "$ROOT" && composer install --no-dev --optimize-autoloader --no-interaction)
    ok "vendor/ installed"

    if [ "$SKIP_BUILD" -eq 1 ]; then
        warn "skipping asset build"
        return
    fi

    printf '  building front-end assets\n'
    # Order matters: the Filament theme imports CSS out of vendor/, so Composer
    # has to have run first.
    (cd "$ROOT" && npm ci && npm run build)
    ok "public/build compiled"
}

prepare_env() {
    head1 "Environment"

    if [ -f "$ROOT/.env" ]; then
        ok ".env exists, left as is"
        return
    fi

    cp "$ROOT/.env.example" "$ROOT/.env"
    ok ".env created from .env.example"
}

# --- main -------------------------------------------------------------------

printf 'FaPost Core — bare-metal bootstrap\n'
printf '%sChecks the host, installs dependencies, then hands over to the installer.%s\n' "$C_DIM" "$C_OFF"

run_checks

if [ "$PROBLEMS" -gt 0 ]; then
    printf '\n%s%d problem(s) found.%s Fix them and run again.\n' "$C_ERR" "$PROBLEMS" "$C_OFF"
    printf 'See docs/deployment/requirements.md for the full list.\n'
    exit 1
fi

printf '\n%sHost is ready.%s\n' "$C_OK" "$C_OFF"

if [ "$CHECK_ONLY" -eq 1 ]; then
    exit 0
fi

install_dependencies
prepare_env

head1 "Configuration"
printf '  Handing over to the installer.\n\n'

# Everything from here — connection settings, migrations, the first tenant — is
# artisan's job, so it behaves identically on bare metal and in a container.
cd "$ROOT"
exec php artisan install
