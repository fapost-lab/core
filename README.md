# FaPost Core

[![License: Apache 2.0](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](./LICENSE)
[![PHP 8.4](https://img.shields.io/badge/php-8.4-777bb4.svg)](https://www.php.net/releases/8.4/en.php)
[![Laravel 12](https://img.shields.io/badge/laravel-12-ff2d20.svg)](https://laravel.com)
[![Docs](https://img.shields.io/badge/docs-docs.fapost.in-5a6e58.svg)](https://docs.fapost.in)
[![Status: active development](https://img.shields.io/badge/status-active%20development-b07c2c.svg)](https://docs.fapost.in)

**A platform for building conversational assistants.** An assistant connects to messaging channels such as
Telegram and WhatsApp, holds conversations with contacts, and runs them through flows designed on a visual
canvas — with contacts, segments, broadcasts, and conversation history around it.

📖 **[docs.fapost.in](https://docs.fapost.in)** — full documentation · [fapost.in](https://fapost.in) — project site

## What it does

A flow is a directed graph stored as JSON. Each node does one thing — send a message, wait for a reply,
branch on a condition, call an HTTP endpoint, query a knowledge base — and edges connect a node's outcomes
to whatever comes next. At runtime the engine walks that graph for one contact, resolving each node's
handler by the pair `(type, version)` and carrying state between steps.

A handler never decides which node runs next. It reports which outcome occurred and the graph decides where
that leads, which is what makes a flow editable by someone who does not write code and a node reusable in
flows its author never saw.

Three properties shape most of the platform's rules:

- **Tenancy is a runtime coordinate, not a layer.** One installation serves many independent organisations,
  each isolated in its own schema. Code that needs tenant context and lacks it fails immediately rather than
  reading someone else's data.
- **Flows are versioned documents.** A running session holds the definition it started with, so publishing a
  change affects the next conversation rather than the one in progress.
- **Queues are separated by purpose.** A broadcast to fifty thousand contacts must not delay the reply to
  the person who just asked a question.

## Architecture

```text
app/Domains/       Bounded contexts: Tenancy, Flow, Messaging, Contact, Assistant,
                   Channels, Conversation, Broadcasting, Media, Staff, Webhook, …
packages/          fapost/foundation — public contracts for Solutions and Plugins
                   fapost/support    — reusable primitives and builder schema fields
gateway/           Optional Go service that verifies and queues provider webhooks
resources/js/      Vue flow builder (Inertia)
```

`fapost/foundation` is the extension boundary. A Solution or Plugin depends on it and never on `App\…`,
which is what lets Core refactor its internals without breaking what is built on top. Both packages are
published separately under [fapost-lab](https://github.com/fapost-lab).

## Stack

- PHP 8.4
- Laravel 12 
- PostgreSQL with landlord/tenant connections
- Redis for cache, queues, locks and the webhook registry
- Horizon
- Filament
- Inertia + Vue 3
- Vite + Tailwind CSS 4
- PHPUnit 12
- PHPat architecture rules

## Quick start

```bash
composer run setup
composer run dev
```

`setup` installs dependencies, prepares `.env`, generates the key, migrates, and builds assets. `dev` starts
the application server, Horizon, log tailing, and Vite together.

With Docker instead:

```bash
cd docker && make dev
```

Full requirements and both installation paths are in
[Local setup](https://docs.fapost.in/contributing/local-setup).

## Checks

```bash
composer test          # PHPUnit suites
composer run test:arch # PHPat architecture rules through PHPStan
vendor/bin/pint --dirty
```

The architecture rules are the executable form of the platform's boundaries — dependency direction,
migration isolation, tenant-aware execution. A change that violates one fails here rather than in review.

## Status

Active development, pre-1.0. The flow engine, tenancy, channels, messaging, and the builder are in use;
several product areas are still partial. The documentation says so explicitly wherever something is not
finished, rather than describing an intention as a fact — so
[docs.fapost.in](https://docs.fapost.in) is the place to check what a given feature actually does today.

## Contributing

Read [Contributing](https://docs.fapost.in/contributing/local-setup) first — it covers the repository
layout, the boundaries that are enforced, and the conventions Core code follows.

Contributions are accepted under the [Contributor License Agreement](./CLA.md). You keep the copyright in
your work; the agreement grants the project the rights it needs to distribute it. Accepting it is a line in
the pull request description.

## Licence and trademark

FaPost Core and both packages are licensed under the [Apache License 2.0](./LICENSE). You may use, modify,
and distribute the code — including in closed-source Solutions and Plugins built on the platform — under the
terms of that licence.

The licence covers the code, not the name. Use of the FaPost name and logo is governed by the
[Trademark Policy](./TRADEMARK.md): referring to the project, describing compatibility, and redistributing
unmodified releases need no permission; naming a fork, a hosted service, or your own product after FaPost
does.
