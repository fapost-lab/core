---
id: adr-20261010-solution-manifest-is-data-in-composer-json
type: adr
status: accepted
date: 2026-10-10
domains:
  - solution
paths:
  - "app/Domains/Solution/**"
summary: A Solution's manifest is data in its composer.json (extra.fapost, schema 1) with the rules owned by Foundation
---
# A Solution's manifest is data in its composer.json, with the schema owned by Foundation

## Context

The Foundation 0.12 manifest was a PHP object built in the provider's `getManifest()`, so it could not
be checked without loading the package, and an error surfaced in `boot()` of every request. The
solution-activation-lifecycle spec requires a manifest that fails on `platform:update` with the name
of the wrong field. The format is the irreversible part: third-party packages will write it, and plans
of the SaaS operator and the activation store key on its `id`.

## Decision

- A Solution is a package with `type: fapost-solution` and an `extra.fapost` block in its own
  `composer.json`. Version and Foundation constraint are the package's `version` and
  `require["fapost/foundation"]`; they are not repeated. `fapost/core` may not be required (ADR-05).
- Schema 1 fields: `schema`, `id` (`^[a-z][a-z0-9_]{1,31}$`, not `core`, `platform`, `fapost`, `app`,
  `system`, `flow`, `module`, `rag`, never changes), `name` (one English line, at most 60),
  `description` (at most 280), `provider`, `actions` (each prefixed `<id>.`). Keys `x-*` are ignored;
  any other unknown field is an error.
- The rules live in Foundation as a pure parser, so a Solution's CI can run them without Core. Core adds
  the installation-level checks (provider class, id collisions) and runs everything on `platform:update`.
- Evolution: a new optional field is additive in the same schema and a minor Foundation release, and the
  author requires that Foundation version; a breaking change is a new `schema`, and Foundation reads N
  and N-1 within one major version. The Composer gate on Foundation replaces a runtime semver check in Core.
- Core boots the provider named in the manifest, only for a valid manifest. This is the owner's explicit
  decision (2026-10-10) for this task, instead of waiting for activation: only `register()` bindings run
  today, since `AbstractSolutionServiceProvider::boot()` exits without a registrar; action registration
  comes with activation.
- A `fapost-solution` package may carry no `extra.laravel.providers` entry (owner decision, 2026-10-10):
  any entry, any provider, any spelling, is a violation `extra.laravel.providers: a Solution's provider is
  booted by Core; remove this block`. An empty list is accepted. Otherwise Laravel's discovery would boot a
  provider regardless of manifest validity or, later, activation, defeating fail-closed.
- `getId()`, `getVersion()`, `getManifest()` leave `ActivatableInterface` and `registerManifest()` leaves
  `CoreRegistrarInterface`.

## Alternatives

- A PHP manifest class: not checkable without executing the package.
- A separate `fapost.json`: duplicates version and `require`, is absent from installed.json.
- Discovery by `extra.fapost` alone, without the `type`: invisible to Packagist and `composer show`, and
  a typo in the key silently makes the package a non-Solution.
- A `requires_platform` range on Core: extensions depend on contracts, not on Core (ADR-05).
- Ignoring unknown fields: a Solution written for a newer platform would install with part of its
  behaviour missing.
- Validating on boot or lazily at activation: a per-request cost, or a broken package reaching the
  operator's screen first.

## Consequences

- `id` is permanent: renaming a Solution is a new Solution and a data move by its author.
- A new manifest field needs a Foundation release before any Solution can use it; the UI and migrations
  fields wait for their contracts.
- Core reads installed.json and one composer.json per Solution on a cache miss; `platform:update` and the
  container entrypoint write the cache that avoids it.
- Plugins share the schema but are not discovered yet.
