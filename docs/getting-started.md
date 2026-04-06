# Getting Started

This document describes how to run the current state of the project locally. If the architecture or infrastructure
changes, this file should reflect the actual working setup path, not the desired future one.

## Requirements

- PHP 8.3 or newer
- Composer 2
- Node.js 20+ and npm
- PostgreSQL 15+ or a compatible version
- Redis

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
php artisan test
vendor/bin/pint
```

## To Be Specified Later

- Docker or `docker-compose` setup for the project
- a more production-like local environment
- how Horizon and Octane should be started
- a dedicated guide for tenant-aware runtime setup
