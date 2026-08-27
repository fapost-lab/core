# FaPost Core

[![License: Apache 2.0](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](./LICENSE)
[![PHP 8.4](https://img.shields.io/badge/php-8.4-777bb4.svg)](https://www.php.net/releases/8.4/en.php)
[![Laravel 12](https://img.shields.io/badge/laravel-12-ff2d20.svg)](https://laravel.com)
[![Status: active development](https://img.shields.io/badge/status-active%20development-5a6e58.svg)](./docs/platform/current-state.md)

FaPost Core (`Flow Automation Post`) is the platform kernel for building conversational bots and automation flows. This
repository is intended for core development that later supports separate solution and SaaS repositories.

The codebase is an active Laravel 12 core application with tenant-aware domains, a flow engine, channel/webhook
infrastructure, Filament administration, and a Vue/Inertia flow builder. Some product areas are still partial; use the
documentation map below to distinguish implemented code from target architecture.

## Repository Purpose

- Build the core platform without SaaS logic or tenant control plane concerns.
- Keep architectural rules stable while domain implementation evolves.
- Provide a clear onboarding entry point for developers.

## Documentation Map

- [Documentation Index](./docs/README.md) - stable tracked documentation entry point.
- [Full Documentation Index](./docs/INDEX.md) - complete map for roadmap, tasks, ADRs, specs, plans, and archive.
- [Platform Docs](./docs/platform/README.md) - build and maintain FaPost Core.
- [Developer Portal](https://docs.fapost.in/extending/extension-model) - HTML docs for future Features, Solutions, Plugins, nodes, and builder extensions.
- [Reference](./docs/reference/README.md) - detailed specs, schemas, and diagrams.
- [Getting Started](https://docs.fapost.in/contributing/local-setup) - local setup, dependencies, and basic commands.
- [Current Project State](./docs/platform/current-state.md) - what actually exists in the repository today.

## Technology Stack

Current stack based on code and configuration:

- PHP 8.4
- Laravel 12
- PostgreSQL
- Redis
- Horizon
- Filament
- Inertia + Vue 3
- Vite + Tailwind CSS 4
- PHPUnit 12

## Project State

The repository currently contains:

- domain modules under `app/Domains/*` for tenancy, assistants, channels, contacts, flow, messaging, media, staff, and
  shared infrastructure;
- landlord and tenant migrations under `database/migrations/landlord` and `database/migrations/tenant`;
- a versioned Flow Engine with node handlers, validation, publishing, sessions, logs, routing, concurrency controls, and
  a Vue builder;
- Telegram channel support and a registered but not implemented WhatsApp adapter;
- Filament resources/pages for current admin surfaces;
- local foundation/support packages under `packages/`;
- PHPUnit suites under `tests/Unit` and `tests/Feature`, plus separate PHPat architecture rules under
  `tests/Architecture`.

Known partial areas are tracked in [Current Project State](./docs/platform/current-state.md) and [Tasks](./docs/platform/TASKS.md).
Examples: managed Broadcast entities, Conversation Logging, Contact Segments, Knowledge Bases, RAG providers, full event
chain flow start, and WhatsApp transport are not complete product features yet.

Development work must keep two things separate:

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
composer run test:arch
composer run docs:build
```

More details and environment requirements are documented in [Getting Started](https://docs.fapost.in/contributing/local-setup).

## Documentation Principle

Documentation in this repository should answer four questions:

1. How do I run the project locally?
2. What already exists in the project today?
3. What architecture is considered the target?
4. Which conventions are mandatory when adding new code?

If an architectural decision changes, update the stable summary in `docs/` and the corresponding ADR/platform document.
For detailed specs and task statuses, use `docs/INDEX.md` and `docs/platform/TASKS.md`.

## License and Trademark

FaPost Core is licensed under the [Apache License 2.0](./LICENSE). The same applies to `fapost/foundation` and
`fapost/support`. You may use, modify, and distribute the code — including in closed-source Solutions and Plugins built
on top of the platform — under the terms of that license.

The license covers the code, not the name. Use of the FaPost name and logo is governed by the
[Trademark Policy](./TRADEMARK.md): referring to the project, describing compatibility, and redistributing unmodified
releases need no permission; naming a fork, a hosted service, or your own product after FaPost does.

Contributions are accepted under the [Contributor License Agreement](./CLA.md). You keep the copyright in your work;
the agreement grants the project the rights it needs to distribute it. Accepting it is a line in the pull request
description.
