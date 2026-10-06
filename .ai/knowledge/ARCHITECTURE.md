# Architecture

High-level map of the system: what the domains are, where the boundaries lie, which direction
dependencies may point, and which constraints hold system-wide. Per-domain detail lives in
`domains/<domain>/`; binding rules are indexed in `RULES.md`; the product intent behind the
system is in `docs/idea-brief.md`.

## System

FaPost Core is the runtime and domain platform for conversational assistants in messengers. It is
the shared execution kernel: solutions live in separate repositories, and a SaaS control plane
(billing, onboarding) is a separate repository on top of Core, never part of it. Staff build flows in a
visual builder; contacts talk to an assistant on a channel, and the flow engine runs their
sessions. The platform is extended from outside through public contracts, not by editing Core.

| Container | Technology | Role |
|---|---|---|
| Laravel application | PHP 8.4, Laravel 12, PHP-FPM | Webhook ingress, Filament admin and assistant panels, builder and mini-app endpoints |
| Queue workers | Laravel Horizon | Flow execution, message delivery, transcript logging, trigger fan-out |
| Ingress gateway | Go, optional | Verifies, rate-limits, deduplicates and enqueues webhook deliveries in front of the application |
| Landlord schema | PostgreSQL | Tenants and the webhook registry |
| Tenant schemas | PostgreSQL | Every domain's data, one schema per tenant, selected by switching the connection's search path |
| Redis | Redis | Queues, locks, cache, sessions and the webhook spec registry |
| Front ends | Inertia + Vue 3 (builder), Vue 3 + vue-router (mini app), Tailwind (public site), Filament theme | Separately built surfaces |

## Domains

| Domain | Code | Responsibility | Must not |
|---|---|---|---|
| `tenancy` | `app/Domains/Tenancy` | Tenant context, schema switching, provisioning, landlord and webhook registry | Gain outward dependencies; every other domain depends on it |
| `flow` | `app/Domains/Flow`, `app/Infrastructure/Flow`, `app/Jobs/Flow` | The runtime: routing, engine, node handlers, state, session lock, subflows, triggers, content translation | Depend on messaging provider implementations; use the container or facades in the runtime |
| `flow-builder` | `resources/js/builder`, builder controllers, Flow draft/validate/publish services | Authoring: drafts, validation, publishing, the visual editor | — (build-time code under PHP-FPM, outside the runtime isolation rules) |
| `channel-ingress` | `app/Domains/Webhook`, `gateway/` | Inbound webhooks, signatures, handoff to the queue — in PHP and in the Go gateway | Touch Eloquent or the tenant context in the controller |
| `channels` | `app/Domains/Channels`, `app/Domains/Messaging` | Channel aggregates, platform integrations (Telegram), outbound senders | — |
| `contact` | `app/Domains/Contact` | Contacts, tags, groups, segments | — |
| `conversation` | `app/Domains/Conversation` | Transcript, operator inbox, takeover | — |
| `broadcasting` | `app/Domains/Broadcasting` | Broadcasts, recipients, fan-out with backpressure | — |
| `media` | `app/Domains/Media` | Media storage, deduplication, channel asset cache | — |
| `assistant` | `app/Domains/Assistant` | The assistant aggregate and the current-assistant context | — |
| `staff` | `app/Domains/Staff` | Staff users, roles, staff notifications | — |

`Presale` (the public site's lead form) and `Shared` (base model concerns) carry no domain
logic and have no knowledge pack.

Domain folders are bounded contexts in naming more than in isolation: Flow reads Contact, Staff,
Assistant and Media models directly, and import cycles exist (for example Contact↔Flow through
`NotifyNodeHandler`). The rules below are the boundaries that do hold.

## Boundaries and dependency direction

- **Extensions depend on contracts, never on Core.** `fapost/foundation` holds the public
  contracts, DTOs and enums; `fapost/support` holds reusable primitives and depends only on
  Foundation; Core, Solutions and Plugins depend on both. A contract an extension needs goes into
  Foundation, a primitive with no domain meaning into Support, anything with one domain's meaning
  stays in Core (ADR-05).
- **The extension packages are separate repositories.** Core consumes them through VCS
  repositories (`fapost/foundation ^0.4`, `fapost/support ^0.2`); they are absent from this working tree and, for local development,
  symlinked over `vendor/` by `composer dev:link` (`tools/dev-link-packages.php`).
  Until 1.0 a package that depends on another FaPost package accepts its whole 0.x line
  (`fapost/support` requires `fapost/foundation >=0.2 <1.0`), so a Foundation minor needs no release
  of Support; from 1.0 constraints go by major.
- **Packages Core does not require enter through a composer overlay.** A private or third-party
  extension is never named in Core's `composer.json` or `composer.lock`: an untracked
  `composer.overlay.json` is merged into `composer.local.json` and installed against Core's lock by
  `tools/composer-overlay.php`, which refuses any package Core requires or locks, so an overlay can
  never move a Core version. The same script serves local development, images derived from Core and
  an extension's CI.
- **The extension surface is partly built.** Present: the action handler registry
  (`Flow/Action/ActionHandlerRegistry`) and the builder's vendor-component contract, a static Vite
  glob in `resources/js/builder/utils/vendorComponents.ts`. Absent: a Core implementation of
  `CoreRegistrarInterface`, Solution activation, and manifest validation. The registered
  `HrSolutionServiceProvider` is an empty stub on the framework's own provider.
- **Landlord data is reached only through Tenancy.** Other domains depend on
  `Tenancy/Contracts`; the `landlord` connection is opened only inside Tenancy infrastructure. An extension package may
  open it for its own prefixed tables, never for Core's
  (`adr-20261005-extension-packages-own-landlord-tables`).
- **Cross-domain asynchronous work goes through named queues** separated by purpose:
  `flow.execution`, `messaging.broadcast`, `messaging.system`, `messaging.logging`,
  `scheduled.triggers`; `messaging.transactional` and `sync.external` are reserved and have no
  producer today.
- **The Flow runtime is constructed, not looked up.** The engine, handlers, orchestration, registry,
  routing, state and subflow code receive collaborators through constructors; node handlers are
  registered by class and built per resolve in the current scope (ADR-0001). Build-time Flow code
  runs under PHP-FPM, one request per process, and is outside this rule.
- **One state model.** Flow state is read by `ScopedStateReader` and written through
  `stateChanges` → `FlowSessionPersister` and `ContactWriter`; the canonical namespaces are the
  Foundation `StateNamespace` enum (ADR-0002).

## Runtime flows

1. **Inbound message.** The provider delivers a webhook either to the Go gateway, which verifies,
   rate-limits, deduplicates and enqueues it, or to `POST /webhook/{channel}/{hash}`
   (`WebhookController`). A job on `flow.execution` runs `MessageRouter` → `FlowOrchestrator` →
   `FlowEngine`, which takes the session lock and runs handlers resolved by `(type, version)`.
   Replies are sent inline: `FlowMessageSender` → `MessageSender::send()` runs inside the
   `flow.execution` job (`messaging.transactional` is reserved and has no job); the transcript is persisted on `messaging.logging`.
2. **Triggers and wake-ups.** Scheduled and event triggers start sessions from jobs on
   `scheduled.triggers`; a `delay` node wakes its session through `ResumeDelayedFlowSessionJob`
   on `flow.execution`, and send-message timeouts through `ResumeTimedOutSendMessageNodeJob`.
3. **Broadcast fan-out.** `BroadcastDispatcher` → `RunBroadcastJob` → `SendBroadcastRecipientJob`
   on `messaging.broadcast`, throttled by backpressure before the provider limit is hit.

## System-wide constraints and invariants

- **Every install runs the multi-tenant runtime.** Core fails fast without a tenant context and has
  no default-tenant fallback, so a self-hosted single-tenant install still carries the tenancy
  machinery. See `RULES.md`.
- **Queue workers are long-lived.** PHP-FPM serves HTTP one request per process; Horizon workers
  run many jobs per process, so job and tenant state lives in `scoped` bindings and never in a
  singleton. There is no long-lived PHP request runtime: Octane was removed (ADR-01, cancelled
  in August 2026) and the Go gateway is the fast ingress path.
- **Identifiers are ULIDs** stored in PostgreSQL `uuid` columns (ADR-03).
- **The runtime backends come from the environment.** The framework config keeps SQLite and the
  database driver as fallbacks; `.env.example` selects PostgreSQL and Redis. Locally PHPUnit uses
  in-memory SQLite, an array cache and a sync queue, so locks and queue concurrency are exercised
  only against mocks; CI additionally runs the suite on PostgreSQL 15.
- **There is no shared front-end design system.** The public site (`resources/css/app.css`), the
  builder (`resources/css/builder.css`) and the Filament theme (`resources/css/filament/theme.css`)
  each define their own tokens — the builder and the Filament theme duplicate one palette under
  different names — and no component is shared across surfaces.
- **Only one messenger integration is real.** Telegram is implemented; the WhatsApp adapter throws
  on every method. Channel swappability is a property of the contracts, not yet a demonstrated one.
