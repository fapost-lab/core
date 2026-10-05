# Tenant quotas — limited test and trial tenants

Depth: normal — a handful of load-bearing decisions (where limits live, what is counted, what
happens at the cap); the rest follows from them.

## Idea

> мне нужно решение для тестеров, или триал, ограниченных возможностей. Т.е. я хочу выставить
> ядро в сеть, и дать возможность ее тестировать, но при этом, я не хочу чтобы тестеры могли
> просто использовать конструктор для сових целей. Поэтому хочу ограничить но не функционально,
> а например число записей. Например не более двух ассистентов или не более 10 флоу. Или не
> больше 100 контаков. Что то типа такого. При этом я хочу этот механизм потом использовать в
> СааС решении как триал версию.

## Goal and problem

- Who is worse off without this, and how: the platform owner cannot put Core online for
  testers without handing out a free, unlimited production tool; later, the SaaS has no trial
  tier.
- What is true when the work is done: one Core deployment serves many tenants by host; every
  creation and runtime path asks a Foundation quota contract before doing work, and refuses what
  it denies; an operator package can provision a tenant and stop its runtime while its panel
  stays readable; no flow can reach the private network. A tenant with no plan — every
  self-hosted install — sees no difference.

## Facts from the code (2026-10-05)

- One deployment serves one tenant: `ConfigTenantResolver` resolves `tenancy.default_tenant_slug`,
  not the request host. Tenants are created only by `platform:install` and `loadtest:seed`; there
  is no self-service signup.
- No plan, tier or feature concept exists. `TenantSettings::$max_contacts` (tenant schema,
  editable by the tenant admin through `TenantSettingsPage`) is declared and never read.
- Contacts are created implicitly by inbound messages (`IncomingMessageJob` →
  `ContactService::findOrCreate`), not by staff.
- Core makes no LLM calls itself; RAG adapters come from extensions. No usage accounting exists.
- Policies with `create()` exist for most entities and drive the Filament Create buttons, but the
  runtime paths (inbound contacts, `CreateFlowAction`, `Broadcast::create`, the load-test seeder)
  bypass them.

## Stress test

From an independent failure hunt (2026-10-05), sharpest first.

- Hidden assumptions — "this holds only if …":
  - Record counts stop real use only if a real small business needs more than the cap. It does
    not: one assistant, a few flows and one channel run a small shop bot. Counts alone are a UX
    boundary, not a deterrent — hence volume and time limits (see Decisions).
  - Per-tenant limits mean something only if each tester has their own tenant. Today one
    deployment serves one tenant, resolved from env; host-based resolution is a prerequisite.
  - The cap holds only if every creation path goes through the guard. Filament replicate
    actions, imports, seeders, flow runs and future Solution packages can write models directly.
- The main trade-off: strict enforcement (atomic counters, an architecture rule over every
  create path) against the cost of touching every domain that creates countable records.
- The weakest point: the flow `call` node. Any tester with one flow can make the platform an
  open HTTP proxy or probe internal hosts (SSRF — server-side request forgery: making the server
  call addresses the attacker chooses, including the private network). No record cap touches
  this; a public stand is unsafe without an egress guard regardless of quotas.
- Failure modes — cause, what breaks, the signal that shows it:
  - Contact churn: deleting contacts frees slots, so a "current count" cap is a rolling window.
    Signal: deleted contacts far outnumber live ones.
  - Contact griefing: the bot username is public; strangers can fill the contact cap and lock
    the tester's own test accounts out. Signal: "bot stopped answering" while at the cap.
  - Check-then-insert race: parallel inbound workers each see `count < cap` and all insert.
    Signal: counts above the cap in an audit.
  - Unit of "flow": counting published versions blocks iteration; counting logical flows lets
    one huge flow hold everything. Signal: "can't publish, limit reached" complaints.
  - Expiry without defined behaviour: running sessions, pending delay wake-ups, scheduled
    broadcasts and registered channel webhooks keep going after the trial ends.
  - Two sources of truth: the dead tenant-editable `TenantSettings::$max_contacts` next to the
    landlord limits. Signal: UI and guard disagree.
  - Broadcasts: an unlimited number of broadcasts to the capped contacts can spam through a
    channel account; on WhatsApp that number may belong to the platform owner.
- Other shapes considered, and why this one:
  - One shared demo tenant, reset nightly — rejected: testers see and break each other's work,
    and nothing of it carries over to the SaaS trial, which needs a tenant per customer anyway.
  - Time limit only — rejected as the sole limit: a free month of unlimited production use is
    still free production use; kept as one of three layers.
  - Volume caps only — rejected as the sole limit: counts give the tester a clear, early,
    explainable boundary in the UI; volume caps alone surface only at runtime.

## Scope and non-goals

- In scope (Core): host-based tenant resolution; the egress guard for the `call` node; the Foundation
  quota contract with an allow-everything default and its calls on every creation path, the inbound
  path and the outbound paths; the "runtime stopped, admin read-only" state; the Foundation
  contracts the operator package uses to provision tenants and switch that state; the ADR that
  lets the operator package own landlord tables; removing `TenantSettings::$max_contacts`.
- Not doing: plans, plan assignment, usage counters, MAU counting, trial terms, tester
  provisioning commands — all in the SaaS shell and its own spec; self-service signup; billing;
  a platform admin UI; limiting features or node types by plan.

## Decisions

- Host-based tenant resolution is Core's: Core already owns the host layout (`TenantHost` serves
  tenant panels at `<slug>.<base_domain>` and reserves the base domain for a control plane) and a
  single webhook ingress for all tenants (`webhook_registry` maps the hash to the tenant). What is
  missing is resolving any tenant from the request host instead of the one slug in env, panels on
  `{tenant}.<base_domain>`, and a session cookie per tenant host. Resolution by host is the
  default; binding to one slug from env is an explicit mode for self-hosted installs only, never
  the platform's behaviour. — rejected: a resolver in the shell, because the panels, sessions and
  builder it touches are Core's and the shell would reach into them.
- The shell owns its own landlord tables (subscriptions, usage counters) through its own
  migrations, and creates tenants through Core's provisioning. — rejected: `tenants.config` jsonb,
  because atomic counters in jsonb race and billing will sit next to them later.
- This spec plans Core only: the seams, the egress guard, host resolution and the read-only state,
  and the Foundation contracts the shell implements. Plans, the trial, MAU counting and tester
  provisioning are planned in a spec in the shell repository, which links here. — rejected: one
  spec here for both, because the closed product's design would sit in the open repository and
  Jig files tasks only into its own repository.

- Isolation: one tenant per tester, resolved by host (`<slug>.<base_domain>`); the operator
  creates tester tenants with a console command and a plan. Self-service signup is a separate
  later phase. — rejected: one shared demo tenant, because testers interfere with each other and
  the mechanism would not carry over to the SaaS trial; signup in the first phase, because it
  doubles the first phase for a handful of invited testers.
- Three layers of limits: record counts (assistants, flows, channels, staff, …), volume
  (contacts and/or outbound messages per period) and a trial term after which the tenant stops.
  — rejected: record counts only, because a small real bot fits inside any sensible cap.
- Contact limit reached: an inbound message that would exceed the limit creates no contact and
  starts no flow; the event is logged and the admin sees a warning in the panel. — rejected: a fixed system reply, because it spends outbound messages and tells end
  users the bot is a trial; letting contacts through and blocking only broadcasts, because then
  the cap is not a cap.
- Split between Core and the SaaS shell (the owner's separate closed package, another repository):
  Core ships only the enforcement seams — Foundation contracts, a default implementation that
  allows everything, a call on every creation and runtime path, and the "runtime stopped, admin
  read-only" tenant state. Plans, plan assignment, usage counters, the trial term and tester
  provisioning live in the shell. — rejected: plans and trials in Core, because open-source Core
  would carry the commercial model and every self-hosted install would carry its code.
- Invariant: Core works both without the shell and with it. Without it, Core binds its own default
  implementation of every operator contract (allow everything, provisioning through Core's
  console commands) and behaves exactly as today; with it, the shell's service provider replaces
  those bindings through Laravel package discovery, and Core never checks whether the shell is
  installed. Core's CI runs without the shell; the shell's CI runs against a released Core. —
  rejected: a `saas` flag or `class_exists` checks in Core, because Core would then know the shell
  exists. Candidate for `RULES.md` when the first seam lands.
- Shell first, contracts on demand: the shell is designed first (its own spec, in its repository);
  then the shell and Core are built in parallel, and each Core seam and Foundation contract is
  added when the shell needs it. The first slice is a walking skeleton: the shell provisions a
  tester tenant with a plan of at most one assistant, the tenant opens on its own host, and Core
  refuses the second assistant. — rejected: building every Core seam ahead of the shell, because
  contracts designed without a consumer guess their shape (the same reason `ecosystem-distribution`
  waits for the first Solution).
- The shell is controlled exactly like `fapost/foundation` and `fapost/support`: its own
  repository from the first commit, checked out under `packages/` (git-ignored here), linked over
  `vendor/` by `composer dev:link`. It depends only on Foundation and Support — never on `App\*` —
  and an architecture test in the shell enforces that. — rejected: developing it inside Core's
  `app/` and extracting later, because the easiest import is always the internal one and the
  extraction never gets cheaper.
- Every Core change made for the shell stands on its own: a default that changes nothing, a
  deny-everything fake in Core's tests, and its own pull request into Core. A Core change whose
  only justification is "the shell needs it" means the contract is wrong.
- Foundation contract changes are batched into releases, not released one method at a time.
- Storage: plans are defined in the shell's code (config: plan key → limit key → number, `null` =
  unlimited); the plan key, per-tenant overrides and the trial end are landlord data the tenant
  cannot reach. The unused `TenantSettings::$max_contacts` is removed from Core. — rejected: a
  `plans` table, because nothing edits it without a platform admin UI that does not exist;
  TenantSettings, because the tenant admin could lift the cap.
- No plan means unlimited: a tenant without a plan (every self-hosted install) behaves exactly
  as today. — taken by Claude at normal depth (reversible): self-hosting must not change.
- Over the cap after a plan change: existing records stay; only creating new ones is refused.
  — taken by Claude (reversible): deleting customer data on a downgrade is never right.
- Enforcement lives in the domain services that create records, through one quota guard
  contract; policies call the same guard only to hide the Create button. An architecture test
  asserts countable models are created only through their services. — taken by Claude
  (reversible): policies do not cover runtime paths; a guard without a rule leaks with every
  new feature.
- The contact limit counts monthly active contacts (MAU — distinct contacts that sent at least one
  inbound message in the calendar month). Deleting contacts frees nothing; a known contact not yet
  active this month counts as new for the month, so with the limit reached it is refused like a
  stranger. — rejected: contacts created during the period, because it does not match how SaaS bot
  platforms bill; the current contact count, because deleting old contacts frees slots.
- Trial end: the bot stops (inbound is not processed, delay wake-ups and broadcasts do not run) and
  the admin panel turns read-only with a call to extend or buy; data is kept for a retention
  period, after which the operator removes the tenant with a command. — rejected: plain
  `Suspended`, because the tester loses access with no explanation, which kills trial conversion;
  automatic deletion, because it is irreversible and unacceptable for a SaaS trial.
- A flow for the limit is a logical flow (`FlowDraft`); publishing versions is free and the size of
  one flow is not limited — volume limits cover the "one huge flow" case. — rejected: counting
  published versions, because it blocks iteration; a node-per-flow cap, because it reaches into
  the builder for little gain.
- Egress guard is part of this spec and gates the public stand: the `call` node never reaches
  private, loopback, link-local or metadata addresses (checked after DNS resolution and on every
  redirect), in Core and for every install; outbound calls per month are one of the plan's volume
  limits. — rejected: a separate task outside the spec, because the stand must not go online
  without it; disabling the `call` node on a plan, because it limits by function, which the idea
  rules out.
- Volume counters are atomic: a usage row incremented with a conditional update
  (`used < limit`) in the same transaction as the insert, not `count(*)` then insert. — taken by
  Claude (reversible): parallel inbound workers make the race real, and `count(*)` on the
  inbound hot path grows with the table.

- Revisions from designing the shell (`fapost/saas`, spec `saas-launch`, 2026-10-05). Where they
  contradict a decision above, these win:
  - Plans live in a landlord table owned by the shell and edited in its operator panel, not in its
    code — the operator panel removed the reason for rejecting a table.
  - Limit keys come from a registry in Foundation: Core (and later Solutions) register each key
    with a label, a unit and a kind (records, per period, bytes); the shell builds its plan form
    from it.
  - Per-period limits reset on the subscription's period, monthly from its start — not the
    calendar month, which would give a trial started late in the month two allowances.
  - Usage is recorded idempotently by natural key in the shell's landlord tables, with overshoot
    bounded by the number of parallel workers. The "atomic counter in the same transaction"
    decision above is withdrawn: landlord and the tenant schema are separate connections.
  - The tenant's access mode is asked, not switched: Core asks the operator contract on every
    request and job, the shell answers from the subscription. Core's default answers "active".
  - The slug is reserved in Core: signup creates the landlord row in a new `Pending` status with no
    schema, and provisioning completes that row idempotently by tenant id, so a failed or killed
    run resumes instead of losing the slug.
  - Solutions are entitled by plan: Core's activation screen asks the operator contract which
    installed Solutions a tenant may activate; Core's default allows all of them.
  - The base domain needs a platform route context with no tenant: today `TenancyMiddleware` runs
    on the whole `web` group, Livewire's update endpoint included, and the guest redirect points at
    a tenant panel route.
  - Core jobs and commands must respect the access mode: jobs load the tenant by id and never check
    its state, and the tenant console commands iterate only active tenants, so a stopped tenant
    would miss deploy migrations.
  - Found by the failure hunt and filed separately: the schema name can exceed PostgreSQL's
    63-byte identifier limit and two slugs can share a schema (task
    `fix-tenant-schema-name-truncation`).

## Open questions

- How a closed package gets into an install: Core's `composer.json` cannot require a private
  package, and `dev:link` only replaces packages Composer already installed. The SaaS build
  (and local development) needs a way to add the shell on top of Core without committing it here
  — first question of the shell's design; same as `ecosystem-distribution`'s "what a paid closed
  extension needs in order to be installable at all".

- Shell data in the landlord database contradicts the Core rule "landlord data is reached only
  through Tenancy" — the rule needs an amendment (ADR) naming the operator-level package as the
  one other owner of landlord tables, before the shell writes its first migration.
- How the shell provisions a tenant without depending on Core: extensions depend on contracts, so
  provisioning (and the "runtime stopped / read-only" switch) needs a Foundation contract that
  Core implements.
- Exact list of count limits and volume limits for the first tester plan, and the trial term and
  retention period — numbers, decided in the shell's spec.
- Which admin pages stay usable in read-only mode (viewing conversations and flows: yes; the
  builder: open but no save?) — decides how deep the read-only switch reaches into Filament and
  the builder.

## Assumptions left untested

- A per-host session cookie is enough to keep tenants' panel sessions apart — taken at normal
  depth; tested by logging into two tenant hosts in one browser.
- Every creation path of a countable model can be routed through its domain service without
  breaking Filament's create pages — taken at normal depth; tested when the architecture rule is
  first switched on and the violations are counted.
- A quota check per inbound message adds no noticeable latency when the shell's implementation is
  an indexed counter — taken at normal depth; tested by the load-test harness with a plan set.
