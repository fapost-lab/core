# FAPost Core

FAPost Core (`Flow Automation Post`) is the platform kernel for building conversational bots and automation flows. This
repository is intended for core development that later supports separate solution and SaaS repositories.

The codebase is still at an early stage: the actual implementation is currently close to a base Laravel application,
while the target domain architecture is described separately. This README is the developer entry point and links the
working documentation set.

## Repository Purpose

- Build the core platform without SaaS logic or tenant control plane concerns.
- Lock down architectural rules before active domain development begins.
- Provide a clear onboarding entry point for developers.

## Documentation Map

- [Getting Started](./docs/getting-started.md) - local setup, dependencies, and basic commands.
- [Current Project State](./docs/current-state.md) - what actually exists in the repository today.
- [Architecture](./docs/architecture/README.md) - the target core model, layers, and principles.
- [Documentation Roadmap](./docs/documentation-roadmap.md) - what should be detailed next.

## Technology Stack

Current stack based on code and configuration:

- PHP 8.3+ (`composer.json` allows `^8.3`)
- Laravel 13
- PostgreSQL
- Redis
- Vite + Tailwind CSS 4
- PHPUnit 12

Target stack according to the architectural direction additionally includes:

- Horizon
- Octane
- Filament
- Inertia + Vue
- Multi-tenancy via PostgreSQL schema per tenant

These parts should be treated as planned until they are reflected in the code and configuration.

## Project State

The repository currently contains:

- standard Laravel bootstrap;
- base migrations for `users`, `cache`, and `jobs`;
- minimal web routing;
- test structure under `tests/Feature` and `tests/Unit`.

The domain directories and infrastructure described in `CLAUDE.md` do not exist yet: `Domains`, tenancy runtime, flow
engine, messaging pipeline, and feature/plugin boot lifecycle.

Because of that, development work must keep two things separate:

- the actual state of the repository;
- the target architecture the project is moving toward.

## Basic Commands

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run dev
```

Or via composer scripts:

```bash
composer run setup
composer run dev
composer test
```

More details and environment requirements are documented in [Getting Started](./docs/getting-started.md).

## Documentation Principle

Documentation in this repository should answer four questions:

1. How do I run the project locally?
2. What already exists in the project today?
3. What architecture is considered the target?
4. Which conventions are mandatory when adding new code?

If an architectural decision changes, update the corresponding document in `docs/` before changing the code.
