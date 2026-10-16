---
id: adr-20261016-connector-package-type
type: adr
status: accepted
date: 2026-10-16
domains:
  - solution
  - channels
paths:
  - "app/Domains/Solution/**"
reviewed_at: 2026-10-16
---
# A messaging channel is a connector: a deploy-time Composer package of type `fapost-connector`, booted for the whole installation, in one id space with Solutions

## Context

The `channel-connectors` spec moves messaging channels out of Core. A channel is a basic extension:
without one Core talks to nobody. The format of its package is the irreversible part, because packages
written by other people will carry it, and the SaaS operator will key rights on its id. Foundation already
holds the Solution manifest (`adr-20261010-solution-manifest-is-data-in-composer-json`), and the docs called
"a new channel adapter" an example of a Plugin, a runtime extension that does not exist yet.

## Decision

- A connector is a Composer package with `type: fapost-connector` and an `extra.fapost` block, schema 1:
  `schema`, `id`, `name`, `description`, `provider`, `channels`; keys `x-*` are ignored, any other unknown
  field is an error. `id`, `name`, `description`, `require` (`fapost/foundation` required, `fapost/core`
  forbidden) and the ban on `extra.laravel.providers` follow the Solution rules, shared in Foundation
  (`Manifest\Internal\ManifestRules`). `channels` is a required, non-empty list of unique channel types,
  each `^[a-z][a-z0-9_]{1,31}$`: the types the connector owns. The format lives in Foundation
  (`Connector\Manifest\ConnectorManifestSchema`, `ConnectorManifestParser`), since 0.21, experimental.
- The provider extends `Fapost\Foundation\Lifecycle\AbstractConnectorServiceProvider`. Core boots it for
  the whole installation: there is no per-tenant activation. Which tenant may use which channel type is
  the operator's entitlement, not an activation.
- A connector reaches an image at build time, through Composer and the build overlay, like a Solution.
  The runtime zip channel stays for Plugins that only add functionality.
- Ids are one space across extension kinds: a connector's id may not equal a Solution's or another
  connector's, and a channel type may be declared by one connector only. A collision invalidates every
  package in it.
- Core finds connectors where it finds Solutions (domain `solution`, the same `installed.json` scan, the
  same `platform:update`, the same cache file under a `connectors` key) and boots their providers before
  the Solutions'. An invalid connector is never booted, and `platform:update` refuses with nothing changed.
- No `actions` or `ui` in schema 1; they are additive when a connector needs them. Channel registration
  (sender, adapter, ingress spec) is not part of this decision: it comes with the connector registrar.

## Alternatives

- A connector as a runtime zip Plugin: rejected by the owner (2026-10-16); a connector is basic, it
  belongs in the build, and Composer resolves its dependencies.
- A connector as a Solution with a channel surface: per-tenant activation is needless for a transport,
  and channels would show in the Solution catalog.
- One `ManifestParser` branching on the type: two kinds' fields and messages mixed, and an edit to one
  risks the other.
- A `kind` field inside `extra.fapost` instead of the package `type`: the manifest ADR already chose the
  `type`, which Packagist and `composer show` see.
- Separate id spaces per kind: cheaper now, impossible to merge once outside packages exist.

## Amendment, 2026-10-16: channel registration

- A connector registers its channels through its own `Fapost\Foundation\Connector\ConnectorRegistrarInterface`
  (`registerChannel(ConnectorChannel)`), not through `CoreRegistrarInterface`: of that interface's seven methods a
  connector would support none. A `ConnectorChannel` names classes (sender, inbound adapter, optional webhook
  registrar and media services); Core builds each inside the request or job that uses it, never shared across
  them (the media registries are scoped).
- A connector's inbound adapter (`Channel\ChannelInboundAdapterInterface`) declares verification and the
  idempotency key only through `IngressSpec`, executed by Core and the gateway; it ships no verification code. A
  provider whose signature the spec cannot express extends `SignatureScheme`. Rejected: moving Core's
  `ChannelAdapterInterface` to Foundation, which ties the public contract to `Illuminate\Http\Request` and to code
  the gateway cannot run.
- Telegram stays built into Core on these seams (owner, 2026-10-16); a connector may never register a type Core
  serves itself. "Built-in" means any `ChannelTypeEnum` case, the WhatsApp stub included: it fails closed, and the
  task that brings a WhatsApp connector removes the stub from Core to free the type.
- A connector's media services reach the Media domain through the same `media.channel.*` container tags Telegram
  uses (Infrastructure tags them); Media never reads `ConnectorChannels`. The Media channel registries are scoped,
  so a connector service lives for one request or job and is never shared across them. Tenant migrations, translations and the public link of a connector channel are not in the
  registrar yet; they arrive with the tasks that first need them.

## Consequences

- Third parties can describe a channel package and, since the amendment, register its channels; a channel of
  that type cannot be created until channel types are open, which is why the format is marked experimental.
- `SolutionManifestValidator` no longer warns about `fapost-connector` packages; the domain `solution`
  now catalogs two kinds of package, and its name lags behind its responsibility.
- The Solution cache file carries a second key; a cache without it is stale for connectors only.
- A self-hosted install whose Solution id equals a future connector's id loses both until one is renamed.
- The connector API reuses value types that live under `Solution\Manifest` (`ManifestViolation`,
  `InvalidManifestException`, the limits and package types on `ManifestSchema`). Moving them to a neutral
  namespace would break Solution authors, so they stay; a third package kind is the moment to introduce
  `Fapost\Foundation\Manifest\` with aliases.
- The id space counts every package that names an id, valid or not: a connector stays out while a broken
  Solution claims its id, so the result does not depend on which of them happens to be valid.
