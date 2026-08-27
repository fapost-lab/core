# Getting Started

This document describes how to run the current state of the project locally. If the architecture or infrastructure
changes, this file should reflect the actual working setup path, not the desired future one.

## Two ways to start

**With Docker** — nothing to install but Docker itself, and the runtime matches
what production uses:

```bash
git clone <repo> && cd fapost-core
cp .env.example .env
cd docker && make dev
```

That builds a development image from the same base as the production one, mounts
your working tree, and starts PostgreSQL, Redis, Horizon, the scheduler and Vite.
Edits take effect immediately — no rebuild. See [With Docker](#with-docker) below.

**On your own machine** — if you already run PHP locally and prefer it that way.
Requirements in that case:

- PHP 8.4 or newer (`composer.json` requires `^8.4`)
- Composer 2
- Node.js 20+ and npm
- PostgreSQL 15+ or a compatible version
- Redis
- PHP extensions: `pdo_pgsql`, `redis`, `intl`, `bcmath`, `gd`, `zip`, plus
  `pcntl` and `posix` for Horizon

Check them all at once:

```bash
./deploy/install.sh --check-only
```

## Initial Setup

1. Install PHP dependencies:

```bash
composer install
```

2. Install repository git hooks:

```bash
composer run hooks:install
```

3. Prepare `.env`:

```bash
cp .env.example .env
php artisan key:generate
```

4. Verify database settings in `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=fapost_core
DB_USERNAME=root
DB_PASSWORD=
```

5. Run migrations:

```bash
php artisan migrate
```

6. Install frontend dependencies:

```bash
npm install
```

## Local Run

To start services separately:

```bash
php artisan serve
npm run dev
```

To use the composer script:

```bash
composer run dev
```

The script starts:

- Laravel dev server
- queue listener
- `pail` log tailing
- Vite dev server

## Tests and Checks

```bash
composer test
composer run test:arch
php artisan test
vendor/bin/pint
```

`composer run test:arch` runs the PHPat architecture rules through PHPStan.

## With Docker

```bash
cd docker
make dev          # build and start; add INSTALL_XDEBUG=1 to include Xdebug
make dev-shell    # a shell inside the application container
make dev-test     # run the suite there
make dev-down     # stop
```

The application is on `http://localhost:8000`, Vite on `http://localhost:5173`.

The development image is the `dev` target of [`docker/Dockerfile`](../../docker/Dockerfile),
built from the same `base` stage as the production one. That is deliberate: a
separately assembled development image would have its own PHP build and extension
set, and every difference between the two turns into a bug that only shows up
after deployment. What does differ is only what should:

- dev Composer dependencies are installed
- the source is mounted rather than copied, so edits are live
- `opcache.validate_timestamps=1`, or nothing you type would take effect
- the container runs as your user id, so files it writes stay yours
- Xdebug is available, off unless the image is built with `INSTALL_XDEBUG=1`

`vendor/` and `node_modules/` are deliberately **not** mounted from the host:
they are installed inside the container for its own platform, and sharing them
would mix binaries built for a different OS.

### Xdebug

```bash
INSTALL_XDEBUG=1 make dev
```

Step debugging is trigger-based, so it costs nothing until you ask for it. The
callback address defaults to `host.docker.internal`, which resolves on Linux too
because compose maps it to the host gateway. If your IDE is somewhere else:

```dotenv
XDEBUG_CLIENT_HOST=192.168.1.50
```

## Running it like production

To exercise the actual production images locally, see
[deployment/docker-compose.md](../deployment/docker-compose.md). Same topology,
different images — useful for reproducing something that only happens with a
compiled config cache and no dev dependencies.
- a dedicated guide for tenant-aware runtime setup
