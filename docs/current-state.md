# Current Project State

This document exists to separate what is already implemented from the target architecture.

## What Exists Today

The application structure currently matches a starter Laravel project:

- `app/Http/Controllers/Controller.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `routes/console.php`
- `database/migrations/*` for `users`, `cache`, and `jobs`

## What Is Confirmed by Configuration

- Default database: PostgreSQL
- Queue driver: `database`
- Cache and sessions: `database`
- Redis is already present in `.env.example`, but it is not yet a required runtime component
- Vite and Tailwind are configured as the frontend toolchain

## What Does Not Exist Yet

- domain structure under `app/Domains/*`;
- tenancy layer;
- separate landlord/tenant database layout;
- flow engine;
- channel webhook routing;
- Horizon/Octane configuration;
- admin panel or frontend builder.

## Developer Implication

When adding new parts to the project, explicitly mark:

- what belongs to the current implementation;
- what is being introduced as a step toward the target architecture;
- which decisions are already fixed as mandatory rules.

Until domain modules appear, the codebase should not be described as an already working DDD core. It is more accurate to
treat this repository as a prepared base for that architecture.
