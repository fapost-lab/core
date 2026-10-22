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
| Laravel application | PHP 8.4+ (images run 8.5), Laravel 13, PHP-FPM | Webhook ingress, the console (admin and assistant screens), builder and mini-app endpoints |
| Queue workers | Laravel Horizon | Flow execution, message delivery, transcript logging, trigger fan-out |
| Ingress gateway | Go, optional | Verifies, rate-limits, deduplicates and enqueues webhook deliveries in front of the application |
| Landlord schema | PostgreSQL | Tenants, the webhook registry and Core's platform-wide settings |
| Tenant schemas | PostgreSQL | Every domain's data, one schema per tenant, selected by switching the connection's search path |
| Redis | Redis | Queues, locks, cache, sessions and the webhook spec registry |
| Front ends | Inertia + Vue 3 (console, builder), Vue 3 + vue-router (mini app), Tailwind (public site) | Separately built surfaces |

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
| `solution` | `app/Domains/Solution` | Installed extension packages — Solutions and, experimental, connectors (`adr-20261016-connector-package-type`): manifest discovery and validation, the catalog and its cache, `platform:update`, per-tenant activation (`ActiveSolutions`, `SolutionActivator`), the console extension registry (`Console/ConsoleExtensionRegistry`) and the check and publication of a Solution's prebuilt console UI (`SolutionUiBundleReader`, `SolutionUiPublisher`, `PublishedSolutionUi`) | Depend on a domain other than the Tenancy contracts, or on the database outside `Infrastructure` |

`app/Infrastructure/<X>` also holds the seams that compose two domains so neither knows the other:
`app/Infrastructure/Solution` writes a Solution's actions into the Flow registry and answers Flow's
`ActionAvailabilityInterface` from `ActiveSolutions`; it also checks a connector's channels against its manifest and
hands them to `Channels\ChannelRegistration`, which writes them into `Channels\ConnectorChannels` and tags their media
services into Media's registries (the same path Telegram's `BuiltInChannelRegistrar` takes). `Domains/Flow` and
`Infrastructure/Flow` do not depend on `Domains/Solution`, and `Domains/Channels` and `Domains/Media` depend on
neither `Domains/Solution` nor `Infrastructure` (`SolutionBoundariesTest`).

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
  repositories (`fapost/foundation ^0.28`, `fapost/support ^0.2`); they are absent from this working tree and, for local development,
  symlinked over `vendor/` by `composer dev:link` (`tools/dev-link-packages.php`). The console kit is
  one too: the npm package `@fapost/ui` (`fapost-lab/ui`), checked out in `packages/fapost-ui` and
  linked over `node_modules/` by `npm run dev:link` (`tools/dev-link-npm.mjs`); it depends on nothing
  in Core. With it linked, Core installs with `npm ci`: `npm install` prunes the checkout's
  `node_modules`.
  Until 1.0 a package that depends on another FaPost package accepts its whole 0.x line
  (`fapost/support` requires `fapost/foundation >=0.2 <1.0`), so a Foundation minor needs no release
  of Support; from 1.0 constraints go by major.
- **Packages Core does not require enter through a composer overlay.** A private or third-party
  extension is never named in Core's `composer.json` or `composer.lock`: an untracked
  `composer.overlay.json` is merged into `composer.local.json` and installed against Core's lock by
  `tools/composer-overlay.php`, which refuses any package Core requires or locks, so an overlay can
  never move a Core version. The same script serves local development, images derived from Core and
  an extension's CI. A package that needs work after installation (publishing its assets) declares
  bare artisan command names in its own `extra.fapost.post-install`; the overlay runs them for
  overlay packages only, so Core never names a package's post-install step — Filament's
  `filament:assets` included.
- **The extension surface is partly built.** Present: the action handler registry
  (`Flow/Action/ActionHandlerRegistry`). The builder's former vendor-component glob
  (`vendorComponents.ts`) is removed: Solution UI goes to the console prebuilt
  (`adr-20261014-solution-console-ui`). Connector packages (`type: fapost-connector`) are found,
  validated and booted for the whole installation beside Solutions, and register their channels through
  their own registrar; channel types are open strings, so a connector's channel is created, receives and sends
  like a built-in one (`adr-20261016-connector-package-type`, experimental). The Solution manifest, a data
  block in the package's `composer.json`, is read and validated by `platform:update` (`solution`
  domain). A tenant's Solution activation is stored in its schema and read only through `ActiveSolutions`
  (`adr-20261014-solution-activation-in-tenant-schema`). Core implements `CoreRegistrarInterface` for
  Solutions, per Solution and for action handlers only (`App\Infrastructure\Solution`), registered once per
  process and checked against the tenant's activation at call time
  (`adr-20261014-solution-actions-registered-per-process`). The console extension contract is built
  (`adr-20261014-solution-console-ui`, experimental): Foundation's `ConsoleExtensionsInterface` is
  implemented by `ConsoleExtensionRegistry` in the `solution` domain, filtered per request and
  rendered by `app/Http/Shell` (`SolutionExtensions`, `SolutionRoutes`, `SolutionImportMap`,
  `SolutionUi`) and `resources/js/console/solutions/`, and `platform:update` publishes the
  prebuilt bundles; its rules are `conventions/solution-console-ui.md`. The activation screen
  (`admin/solutions`) and the operator's entitlement (grants, allowed, the storefront) are in the Solution domain's
  overview. Absent: the other registrar methods, and the author's half of Solution UI (the Vite preset, the kit-range check, the derived-image
  recipe — `first-solution` phase 0).
- **Landlord data is reached only through Tenancy.** Other domains depend on
  `Tenancy/Contracts`; the `landlord` connection is opened only inside Tenancy infrastructure. Only the SaaS operator
  package may open it, for its own prefixed tables and never for Core's; Plugin and Solution packages may not
  (`adr-20261005-extension-packages-own-landlord-tables`, amended 2026-10-08).
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
- **The console is Inertia and Vue; Core has no Filament or Livewire.** `routes/inertia.php` is
  always loaded and serves the admin (`admin/*`) and assistant (`assistant/{assistant}/*`) screens at
  the URIs the removed Filament panels had. Every route is named `console.*`: admin screens
  `console.admin.<resource>.<action>`, an assistant's `console.<resource>.<action>`, reads
  `index|create|show|edit`; the parameters are `{assistant}` and `{record}`.
  `ConsoleRouteInterceptionTest` pins each name to its action and URI. Console routes use the
  `admin` / `console` middleware groups (`bootstrap/app.php`). The shell's navigation
  (`app/Http/Shell`) keeps an `external` flag for an item that leads off the console (a plain link,
  never an Inertia `<Link>`); every Core item has it false. No `Filament\` or `Livewire\` dependency in
  `App\`: an operator package (the SaaS shell) may install Filament for a panel of its own, and Core
  must not lean on it. In Core's CI phpstan's unknown-class errors (level 0) enforce this, since the
  classes are absent; PHPat `NoFilamentTest` bites only where Filament is installed — a local Core
  checkout with the SaaS package added through the composer overlay. No CI runs it there: the SaaS
  package's CI does not run Core's `test:arch` (phpstan).
- **The operator surfaces share one token source.**
  The kit's `tokens.css` (`@fapost/ui/tokens.css`) holds the
  Warm Minimal tokens (light and `.dark`, fonts Onest, Roboto Condensed, Victor Mono, self-hosted
  through Fontsource); the kit (the npm package `@fapost/ui` from `fapost-lab/ui`, shadcn-vue, shipped
  as source, linked from `packages/fapost-ui` for local work, brought to the console by
  `resources/css/ui.css`; `adr-20261015-ui-kit-package-shape`) builds on it, and the builder's
  own variables point at it in both themes. The console shell and live updates are Core's own code
  (`resources/js/console/shell`, `resources/js/console/lib`, alias `@console`), not the kit; the
  theme helpers the console and the builder share are in `resources/js/shared/theme`. The public site (`resources/css/app.css`) keeps its own
  palette by decision (D5, spec `ui-foundation`). The front-end build needs nothing from Composer's
  `vendor/`, so the image's `assets` stage and CI's front-end job build from `package.json` alone, with the kit
  from the public npm registry.
- **Only one messenger integration is real.** Telegram is implemented, and it registers through the same
  registrar contract a connector uses. Channel swappability is a property of the contracts, not yet a
  demonstrated one: no connector package has shipped a second messenger.
