# Extension installer — Core's side of installing, upgrading and licensing from the admin

Depth: deep — money, other people's code running with full rights, and an install path that touches
every tenant's schema; most decisions here are expensive to take back. Written from one conversation
(2026-11-11/12) that produced three specs: `marketplace` (the store, a separate closed product),
`fapost-agent` (the host agent, a separate repository) and this one (what Core itself must do).

## Idea

The owner, translated from Russian: "I want to build a marketplace of plugins and Solutions. A
developer registers and adds a Solution — name, description, category, tags, later compatibility,
price or free. A buyer registers too, sees purchase history, licence terms and renewals. Hence a
licence management module. Think through how to protect products from illegal distribution — maybe
access to the repository by token. Why in this project: I want to install a Solution right from the
admin panel. We said a Solution is installed through Composer and the front end is built — but can
it be done without a developer after all?"

Later in the conversation: "The install agent is a separate product, as I understand it, so it gets
its own spec." And the agent's trigger, the owner's own design: "What if the admin only prepares a
ticket file saying what I want, here are the keys and the id, and a root cron checks for such a
ticket every minute and runs the build?"

## Goal and problem

- Who is worse off without this, and how: a self-hosted operator (an integrator or a company running
  FaPost for itself) cannot add a Solution or upgrade Core without a developer editing a Composer
  overlay and rebuilding an image; the owner cannot sell or even hand out an extension to such an
  installation without that developer in the loop. In SaaS the problem does not exist — only the
  platform owner installs code and tenants activate what is installed — so self-hosted is the problem.
- What is true when the work is done: an operator opens the admin, picks a product from the
  marketplace catalogue (or "upgrade FaPost to 0.30"), and the installation ends up running it —
  with Core never holding rights on the host, with a manual path when no agent is present, and with
  a licence that decides which versions this installation may download.

The front-end half of the original question is already answered: since D4 (2026-10-07,
`solution-activation-lifecycle`) a Solution's UI ships prebuilt in `dist/` and is loaded through the
import map — installing needs no Node. What remains is Composer and the image.

## Stress test

- Hidden assumptions — "this holds only if …":
  - The admin never needs rights on the host. Holds only if everything privileged is done by the
    host agent from a declarative ticket; the moment the web process can start a container it holds
    root on the host, and any installed extension holds it with it.
  - Solution migrations can be applied without downtime. Holds only if they are expand-only — the new
    schema keeps working with the old code — because DDL across N tenant schemas has no common
    transaction and cannot be rolled back.
  - Booting many Solutions is cheap. False under PHP-FPM, which boots every installed Solution on
    every request: a self-hosted install with 5–30 products is fine, the SaaS catalogue with hundreds
    is not, without a compiled registry and lazy providers.
- The main trade-off: an immutable derived image built by the agent (safe, reproducible, needs an
  agent with Docker rights on the host) against writing `vendor/` at run time (no agent, but code
  differs between containers and replicas). Chosen: the image, because the rights live on the host,
  not in the application.
- The weakest point: everything here assumes an agent most operators do not have yet, and the
  installer (`fapost-install`) promises today that it "registers no services and touches nothing
  under /etc". Until the agent ships, the manual path is the product.
- Failure modes — cause, what breaks, the signal that shows it (from the independent failure hunt):
  - Partial upgrade: migrations applied to some tenant schemas, then a failure — some tenants on the
    new schema, some on the old, DDL cannot be undone; signal: errors for some tenants only, differing
    `migrations` tables between schemas.
  - Queued jobs referencing classes the new release removed — `failed_jobs` fills with
    class-not-found after an install; signal: a spike of unserialize errors right after a switch.
  - A compiled registry gone stale after a manual install, or a lazy provider that registered
    listeners or schedules in `boot()` never booting — functionality silently missing; signal: "the
    event is not handled", found only by users.
  - A front-end mismatch: one Vue and one kit version per application through the import map, while
    Composer checks only PHP ranges — a blank screen for one Solution after an upgrade composer called
    successful; signal: browser console errors depending on the set of Solutions.
  - An abandoned product with a narrow Core range blocking every Core upgrade — operators stay without
    security fixes or force it by hand; signal: Core versions in the field freeze.
  - A licence bound to an installation id meets disaster recovery, a move, a restored backup or a
    cloned staging — a legal customer cannot update in the middle of an outage; signal: "licence
    already activated" tickets.
- Other shapes considered, and why this one: see Decisions — runtime `vendor/` on a shared volume,
  a one-off container launched from the admin, and Composer `require` inside a running container were
  all rejected.

## Scope and non-goals

- In scope (Core):
  - The admin side of the installer: compose a ticket, show its status and progress, maintenance mode
    during an install, the manual path when no agent answers.
  - The ticket contract `contracts/installer/` (schema `ticket.v1`, status, golden examples), shared
    with `fapost-agent` the way `contracts/ingress/` is shared with the gateway.
  - Upgrading Core itself through the same ticket.
  - Solution migrations: applied in a tenant's schema on activation, and on upgrade only in tenants
    that activated it; the expand-only rule.
  - A compiled Solution registry written at install time, lazy providers, and a boot-time budget
    checked by a benchmark.
  - The catalogue screen backed by the marketplace API, filtered by this installation's versions.
  - Licence storage: the installation id, licence keys handed to the agent for Composer auth, the
    offline licence file, the update check (which doubles as the heartbeat) and revocation notices.
  - Compatibility checks before a ticket is written: PHP, Foundation, kit and Vue ranges.
  - Zip plugins as a second, lighter channel (decisions carried over from `plugin-runtime-contract-idea`).
- Not doing:
  - The store itself — accounts, publishers, payments, the Composer repository: spec `marketplace`.
  - The host agent — building, switching, caching, offline bundles: spec `fapost-agent`.
  - Turning a SaaS tenant's purchase into an activation: the SaaS shell's side, see Open questions.
  - An in-process sandbox: there is none in PHP; trust is handled by trust levels and signing.

## Decisions

- The marketplace is a separate closed product; Core holds only the client (catalogue screen,
  licence storage, the installer's admin side) — rejected: the store inside Core, because a fork of
  open Core could pose as the store and every installation would carry its code; inside the SaaS
  shell, because self-hosted operators never see the shell.
- In SaaS only the platform owner installs code (or support does on request); a tenant activates what
  is installed. Unchanged from `solution-activation-lifecycle`.
- The admin writes a declarative ticket — what set of packages and versions is wanted, the licence
  keys and the installation id — and a host agent executes it; Core holds no rights on the host. The
  owner's design. Rejected: a one-off container launched from the admin (needs the Docker socket — root
  on the host, granted by a process every extension runs in); `vendor/` written to a shared volume at
  run time (code differs between containers and replicas, opcache and realpath caches serve stale
  code); `composer require` inside a running container (containers diverge, the next deploy reverts
  it).
- The installation result is an immutable derived image — this supersedes, for self-hosted, the
  "composer overlay + derived image built by the operator" half of D4: the agent builds it.
- The ticket is data, never commands: the agent executes only a list of packages and versions,
  checked against its own rules. The contract is versioned (`ticket.v1`) and lives in
  `contracts/installer/` with golden examples both sides test against.
- The same ticket upgrades Core: Composer resolves one dependency tree, so installing a Solution is
  already re-resolving Core's `vendor/`; "upgrade FaPost" from the admin is the same mechanism.
- Without an agent the admin shows the manual path: the overlay to write and the command to run
  (`make update`). It stays a first-class path, not an error.
- Install order: maintenance mode, pause the queues, run migrations, switch the image, resume. Rollback
  is possible up to the migrations and only forward after them, and the admin says so. Queue payloads
  carry ids and scalars only.
- Solution migrations are expand-only, applied in a tenant's schema when that tenant activates the
  Solution, and on upgrade only in tenants that activated it — rejected: running them across every
  tenant on install, because the deploy window then grows with the tenant count. This lifts the
  current ban on `registerMigrations` for Solutions (adr-20261014-solution-actions-registered-per-process),
  which needs its own ADR when built.
- A compiled registry is written at install time from the validated manifests; providers are lazy;
  a benchmark holds a budget for boot time with many installed Solutions. A provider that needs
  `boot()` side effects (listeners, schedules) declares them in the manifest so the compiled registry
  can wire them without booting the provider.
- Compatibility checked before a ticket is written: PHP, Foundation, kit and Vue ranges from
  `extra.fapost`. An install that does not fit is refused; a Core upgrade that would break an
  installed product is blocked, the blocker named, and the operator may choose "deactivate X and
  upgrade" explicitly.
- Licences: bound to an installation id; dev and staging keys are free and unlimited, the limit counts
  production installations only; re-binding to a new id is self-service a limited number of times per
  period (disaster recovery, a move, a restored backup).
- On expiry v1 only stops new versions: versions released while the licence was valid stay
  downloadable forever, the installation keeps working, and the admin shows a notice — rejected for v1:
  deactivation after a grace period, because the owner wants the licensing policy minimal first.
- The update check doubles as the heartbeat: installation id and versions, no tenant data,
  switchable off. It delivers revocation notices; a revoked version prompts the operator to roll back.
- Offline licence file (signed, Ed25519) for air-gapped installations.
- Trust levels: "verified by FaPost" (review, signature, compatibility CI) and "community" (automatic
  checks only, installed after an explicit warning). SaaS installs only the first.
- Zip plugins (from `plugin-runtime-contract-idea`, owner's decisions of 2026-10-16): one mechanism for
  runtime plugins — a zip with its own `vendor/`, installed only by the installation operator, Core's
  libraries in manifest ranges checked at load, extra libraries only prefixed (php-scoper/Strauss),
  registry in landlord, signed releases (Ed25519), unsigned ones only by an explicit operator setting,
  built-in plugins read-only in the image, operator-added ones on a volume. Whether they still need a
  shared volume now that the agent builds images is an open question below.

## Open questions

- Do zip plugins keep their own channel (shared volume, no rebuild), or become one more package type
  the agent bakes into the image? Depends on how slow an agent build is in practice; the scoping risk
  (prefixed vendors break string class names, facades, serialized jobs) argues for baking them.
- How a SaaS tenant's purchase in the marketplace becomes an activation — the SaaS shell's side; needs
  the marketplace's licence model per tenant and the shell's plan → activation mapping.
- What the admin shows when a ticket is waiting and the agent never picks it up — the timeout and the
  hand-off to the manual path.
- Where the licence keys sit between the admin and the agent: in the ticket (on disk, readable by the
  host) or fetched by the agent itself — a decision shared with `fapost-agent`.

## Set aside

- Deactivating a subscription product after a grace period — set aside because the licensing policy
  starts minimal; the fields exist in the marketplace's product model.
- unexplored: a "try before you buy" demo tenant in SaaS for products from the catalogue.

## Assumptions left untested

- A self-hosted installation runs 5–30 products; hundreds appear only in SaaS — taken at deep; the
  registry benchmark tests it.
- Expand-only migrations are acceptable to Solution authors — taken at deep; the first Solution with
  a real schema change tests it.
- Operators will install a host agent at all — taken at deep; how many installs use the manual path
  tests it.
