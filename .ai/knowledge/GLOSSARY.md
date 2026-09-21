# Glossary

Canonical ubiquitous language for this project: the cross-domain terms a newcomer gets wrong.
Product terms as users see them are published in `docs/site/using/glossary.mdx`; terms that
belong to one domain live in `domains/<domain>/GLOSSARY.md`.

## Core

This repository (`fapost/core`): the platform runtime, the admin panels and the flow builder.
Not the extension packages, which are separate repositories.

Informal synonyms: platform, the app.
Use in: documentation, discussion.

## Foundation

The `fapost/foundation` package: the public contracts, DTOs and enums that extensions depend on.
Everything outside Core depends on Foundation, never on `App\…`.

Informal synonyms: contracts package.
Use in: source code (`Fapost\Foundation`), documentation.

## Support

The `fapost/support` package: reusable primitives with no domain meaning. It depends only on
Foundation; domain logic never moves into it.

Use in: source code (`Fapost\Support`), documentation.

## Feature

A capability built inside Core and released with it. The only extension type that may ship UI
into the builder and admin panels directly.

Use in: documentation.

## Solution

A vertical product module (for example HR) in its own Composer package, with its own domain,
tables and screens. Its front end goes through the build-and-publish contract; it never lives
under `app/Solutions` in Core.

Informal synonyms: niche module.
Use in: documentation, source code (`AbstractSolutionServiceProvider`).

## Plugin

A package that registers runtime behaviour with Core — node handlers, transports, commands,
routes, schedules, migrations — through `CoreRegistrarInterface`. It cannot ship Vue components.

Use in: documentation, source code (`AbstractPluginServiceProvider`).

## Tenant

One organisation's installation, isolated in its own PostgreSQL schema. A self-hosted install
has exactly one. Not the Filament "tenant" of the assistant panel — see Assistant.

Informal synonyms: organisation, account.
Use in: source code, schema, documentation.

## Landlord

The platform-wide PostgreSQL schema and connection holding tenants and the webhook registry,
as opposed to the per-tenant schemas. Reached only through the Tenancy domain.

Informal synonyms: central database, public schema.
Use in: source code, schema, documentation.

## Assistant

One bot inside a tenant, with its own channels, flows and contacts. In the assistant panel
(`/assistant/{id}`) Filament's "tenant" is an assistant, not a platform tenant.

Informal synonyms: bot.
Use in: source code, schema, documentation.

## Channel

An assistant's presence on one messaging platform, identified by its webhook hash. The
messaging platform itself (Telegram, WhatsApp) is the *platform*, not the channel.

Informal synonyms: messenger, bot connection.
Use in: source code, schema, documentation.

## Contact

A person who writes to an assistant on a channel. Never a person who works in the admin panels —
that is a staff user.

Informal synonyms: user (avoid), client, subscriber.
Use in: source code, schema, documentation.

## Staff user

A person who works in the admin or assistant panels, with exactly one role. An *operator* is a
staff user taking over a conversation.

Informal synonyms: user, admin, operator.
Use in: source code (`User`), documentation.

## Conversation

The whole exchange with one contact on one channel. It can hold many flow sessions or none;
a flow session is one pass through one flow inside it.

Informal synonyms: dialogue, chat.
Use in: source code, schema, documentation.

## Content language

The language of messages sent to contacts, resolved at runtime per contact and assistant. Kept
apart from the *admin UI language* of the panels, which comes from Laravel lang files.

Informal synonyms: bot language.
Use in: source code, documentation.

## Scoped binding

A container binding rebuilt for every request and every queue job — the lifetime of tenant,
assistant and job state. A *singleton* lives as long as the worker process and must not hold it.

Informal synonyms: per-request binding, job scope.
Use in: source code, documentation.
