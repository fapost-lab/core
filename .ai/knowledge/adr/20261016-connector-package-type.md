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

## Consequences

- Third parties can describe a channel package today; it does nothing until the connector registrar
  exists, which is why the format is marked experimental.
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
