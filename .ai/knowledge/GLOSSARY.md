# Glossary

Canonical ubiquitous language for this project: the cross-domain terms a newcomer gets wrong.
Product terms as users see them are published in `docs/site/using/glossary.mdx`; terms that
belong to one domain live in `domains/<domain>/GLOSSARY.md`.

## Core

This repository (`fapost/core`): the platform runtime, the console (the admin panel and each assistant's
screens, Inertia + Vue) and the flow builder.
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
into the builder and the console directly.

Use in: documentation.

## Solution

A vertical product module (for example HR) in its own Composer package, with its own domain,
tables and screens. It adds to the console through extension points and ships its UI prebuilt
(`adr-20261014-solution-console-ui`); it never lives under `app/Solutions` in Core.

Informal synonyms: niche module.
Use in: documentation, source code (`AbstractSolutionServiceProvider`).

## Extension point

A place in Core's console where a Solution may add UI: an application in the app switcher,
a contextual action, a shell element, a dashboard widget, a settings tab or a table column.
Declared server-side in PHP; its component is referenced by name, `<solution id>::<Name>`
(`adr-20261014-solution-console-ui`).

Use in: documentation, discussion.

## Console target

The named spot in one of Core's screens an extension point attaches to — `contact.view`,
the `assistant` settings, the `contacts` table. Targets are constants in Foundation; Core
lists them, a Solution only chooses among them.

Informal synonyms: hook point.
Use in: source code, documentation.

## Connector

A Composer package of `type: fapost-connector` that brings a messaging channel (Telegram, Viber):
it owns the channel types its manifest lists in `extra.fapost.channels`. Installed at build time like a
Solution, but booted for the whole installation with no per-tenant activation
(`adr-20261016-connector-package-type`).

Informal synonyms: channel driver, channel integration.
Use in: documentation, source code (`AbstractConnectorServiceProvider`).

## Plugin

A package that registers runtime behaviour with Core — node handlers, commands, routes, schedules,
migrations — through `CoreRegistrarInterface`. It cannot ship Vue components. A messaging channel is
a Connector, not a Plugin.

Use in: documentation, source code (`AbstractPluginServiceProvider`).

## Tenant

One organisation's installation, isolated in its own PostgreSQL schema. A self-hosted install
has exactly one.

Informal synonyms: organisation, account.
Use in: source code, schema, documentation.

## Landlord

The platform-wide PostgreSQL schema and connection holding tenants and the webhook registry,
as opposed to the per-tenant schemas. Reached only through the Tenancy domain.

Informal synonyms: central database, public schema.
Use in: source code, schema, documentation.

## Assistant

One bot inside a tenant, with its own channels, flows and contacts. Its console screens live under
`/assistant/{assistant}`.

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

A person who works in the console (the admin panel or an assistant's screens), with one or more
roles. An *operator* is a
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
apart from the *admin UI language* of the console and the builder, which comes from Laravel lang
files.

Informal synonyms: bot language.
Use in: source code, documentation.

## Scoped binding

A container binding rebuilt for every request and every queue job — the lifetime of tenant,
assistant and job state. A *singleton* lives as long as the worker process and must not hold it.

Informal synonyms: per-request binding, job scope.
Use in: source code, documentation.
