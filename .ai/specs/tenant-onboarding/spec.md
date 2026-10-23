# Tenant onboarding — a setup wizard and a checklist

Depth: normal — few decisions are irreversible (the content languages), but the step list must be
open to Solutions and the SaaS shell from the start, and the gate blocks the whole console.

## Idea

The owner's words (2026-10-23, translated): "A new tenant who has just entered the app does not
understand what to do. And if they create an assistant and go on to build flows, they can no longer
change the base language. I propose a small wizard with mandatory steps that helps set the system up
and then redirects to the assistants. Plus, as some programs have, perhaps a checklist of what is
already done and what still needs to be done."

On extensibility: "Today we have the language and the assistant. Later we may add our own support
bot, and we will need to walk the user through it. Then other features may appear, mandatory or not.
So the wizard must be built to extend safely."

## Goal and problem

- Who is worse off without this, and how: the first administrator of a freshly provisioned tenant.
  Provisioning leaves only that administrator — no assistant, no channel, no flow — and the login
  lands on `/admin`, a dashboard of counters with nothing to count. Nothing says what to do first,
  and the one decision that cannot be taken back is easy to miss: the tenant's
  `content_base_language` locks once a flow is published (`TenantSettingsEditor::baseLanguageLocked`),
  and an assistant's `default_language` locks once it has any flow
  (`AssistantSettingsService::languageLocked`).
- What is true when the work is done: a new tenant's first administrator cannot reach the console
  before taking the irreversible decisions and creating a first assistant; afterwards they land on
  that assistant, and a checklist shows what is done and what is left before the assistant answers.
  Core, Solutions and the SaaS shell add steps without touching each other's code.

## Stress test

- Hidden assumptions — "this holds only if …":
  - That an existing tenant is never gated after the release. The language step cannot be derived
    from data (`content_base_language` defaults to `en`, so a value is always there), so a gate
    keyed on step completion alone would lock every working tenant into the wizard on upgrade.
    Answer: the gate settles each tenant once, at runtime (D7) — a tenant that already has an
    assistant when first checked is recorded as onboarded and never gated. No backfill is needed,
    which also keeps `conventions/migration-isolation.md` intact (a migration may not write that
    data). "Has an assistant" cannot be the gate condition itself: the wizard's own assistant step
    creates one, and the required steps after it would no longer hold.
  - That a step's own page is reachable while the gate is up. A Solution's step lives on the
    Solution's routes, outside the wizard; if the gate redirects it, the step can never be done.
    The gate's allow-list is therefore built from the registered steps' routes, not hard-coded.
  - That the person in the wizard understands the language choice. A forced choice made in the first
    minute without understanding is the same trap with a form in front of it: the step's copy has to
    say what locks and when.
  - That every required step can be done by the tenant's administrator alone. A step that needs the
    operator, money or an external account holds the tenant forever; a required step must be
    completable inside the tenant.
- The main trade-off: a hard gate guarantees the irreversible decisions are taken, at the price of
  the console being unusable until they are — every required step is a door the tenant cannot walk
  around, so the bar for "required" stays high: irreversible, or nothing after it makes sense. Every
  step, required or not, also costs the user's patience: the start-up wizard stays a handful of
  steps, and everything that can wait until it is needed waits (D14).
- The weakest point: the gate's correctness depends on every contributor's `isComplete`. A step
  whose check is wrong or throws either never lets the tenant in or lets it in too early, and Core
  cannot see why from the outside.
- Failure modes — cause, what breaks, the signal that shows it:
  - A step's page is not in the allow-list — redirect loop between the wizard and the step; the
    browser reports too many redirects.
  - A contributor's `isComplete` throws — the gate cannot decide; it must treat the step as complete
    and log, never block the console on a contributor's bug — and, found by the failure hunt, never
    write `onboarded_at` on a check that threw, or the bug becomes a permanent "onboarded" (D15).
  - The gate evaluates every step's check on every console request — each page load runs N queries
    from N contributors; `onboarded_at` short-circuits it once the wizard is done, so the cost exists
    only during the wizard.
  - An operator's support session enters an un-onboarded tenant and is redirected into the wizard —
    support cannot look at the tenant without taking its decisions for it (answered by D10).
  - Two contributors interleave their steps — the order is undefined and one's step lands between
    another's; answered by D13: contributors never share a sequence, each owns a block.
- Other shapes considered, and why this one:
  - Remove the trap instead: let the base language change after flows exist — rejected: flow texts
    are written in the base language and translated from it, so changing it means re-keying every
    translation; and the orientation problem ("what do I do first") remains.
  - Ask for the language on the SaaS signup form — rejected as the solution, because self-hosted
    installs have no signup; kept as compatible: the shell may record the language step as done.
  - A checklist only, no wizard — rejected: it orients but does not close the language trap.
  - A starter assistant from a template — not rejected, deferred: it fits later as an optional step.

- Independent failure hunt (a fresh agent, 2026-10-23), ranked by likelihood times cost; the code
  references were checked:
  1. A required step can be impossible: `AssistantService::create` asserts the plan's assistant
     quota (`AssistantService.php:52-54`), and `RefuseWritesWhenTenantStopped` refuses writes in a
     stopped tenant — the gate then holds a console the tenant cannot fix or pay its way out of
     (answered by D16: no such state reaches the wizard).
  2. A throwing check counted as complete plus the one-time write makes the mistake permanent (D15).
  3. The first request decides the tenant's state, whatever it is: the bell's polling XHR
     (`console/notifications/count`), concurrent first requests, an operator's support session —
     and a 302 to an HTML wizard breaks JSON and Inertia partial reloads (D15).
  4. Grandfathering by "has an assistant" gates working tenants that have none — users invited,
     Solutions active, their only assistant deleted (answered by D7: grandfathered by creation time).
  5. A user without the settings permission meets a 403 or a loop instead of a way forward (D15).
  6. The gate on the `admin` group misses `routes/builder.php` and `routes/media.php`, which load
     under `web` with their own stacks (`bootstrap/app.php:49-50`) — a deep link into the builder
     skips onboarding (D15).
  7. Confirming the tenant's languages does not protect the assistant's: a new assistant defaults to
     `en` (`AssistantService.php:62`) and is validated against every content language, not the
     tenant's available ones (`AssistantRequest.php:46`); the first draft flow locks the mismatch (D15).
  8. The SaaS shell is not a Solution: `ConsoleExtensionsInterface` binds every declaration to the
     booting Solution and closes after boot, so the shell's block needs another way in (phase 3).
  9. The late-step notification goes to users one by one (`StaffNotificationInbox`), so admins added
     later never see it (D15).
  10. Derived completion can be satisfied by someone else — an operator's assistant, the platform
      support user counted as "the team" (D15).
  11. A form submitted on a page loaded just before the gate engaged is lost to the redirect (D15).

## Scope and non-goals

- In scope: the step registry in Core with Core's own steps registered through it; the wizard (the
  gate, progress, required and optional steps); the checklist of what is done and left; the banner
  and bell notification for a required step that appears after onboarding; the Foundation contract
  once its first external contributor exists.
- Not doing: the support-bot Solution or its step (it will contribute one); a form-schema engine for
  steps; making the base language changeable; the SaaS shell's own steps (plan, billing) — the shell
  adds them through the Foundation contract; product tours or tooltips over existing screens.

## Decisions

- D1. The wizard holds the console with a hard redirect: until every required step is complete, console
  pages redirect to the wizard (sign-out and the user's own profile stay reachable) — rejected: a
  skippable wizard with a reminder banner, because the language trap stays open exactly as today.
- D2. The wizard is taken by whoever holds the permission to edit tenant settings, not by a named user —
  rejected: binding it to the first administrator, because a tenant whose first admin never signs in
  would be stuck. In practice it is the first administrator anyway: nobody else exists until they
  invite someone, and they cannot invite before finishing the wizard.
- D3. The first steps are the content languages (base and available) and the first assistant; the
  redirect at the end goes to that assistant — rejected: languages only, because "redirect to the
  assistants" then lands on an empty list; and everything up to a first answer (channel token,
  webhook, starter flow), because the wizard then depends on an external token the user may not have
  at hand.
- D4. The step list is open: Core, Solutions (a future support-bot Solution is the first expected one)
  and the SaaS shell contribute steps, required or not, without editing each other's code.
- D5. A step is a page plus a check: key, title, position, `required`, the route of its own page and
  `isComplete()`. The wizard is a shell showing progress and moving to the next step; each step's
  page is drawn by its owner (Core in Inertia, a Solution through its prebuilt console UI and the
  existing `routes()` registration) — rejected: a form schema the wizard renders, because it needs a
  forms engine of its own and breaks on the first step that is not a form (connecting a bot, OAuth).
- D6. A step decides its own completion. Most derive it from data (an assistant exists → done), so
  the checklist never lies when something is deleted; for a step that cannot be derived ("the
  languages were confirmed"), Core keeps a tenant-scoped record of completed steps — rejected: stored
  marks only, because the checklist then drifts from reality.
- D6a. A required step cannot be skipped. An optional step can, and its page says where in the menu
  it can be set up later; a skipped optional step stays in the checklist.
- D7. The hard gate is a one-time event in a tenant's life. The installation records once, in
  landlord settings, the moment onboarding was switched on. The first time the gate sees a tenant
  with no onboarding state, it decides once and records it: a tenant created before that moment, or
  one that already has an assistant (a seed, an import), is recorded as onboarded (`onboarded_at`)
  and is never gated; any other is recorded as started
  (`onboarding_started_at`) and is held until every required step is complete, when `onboarded_at`
  is written. A required step that appears
  later — a Core upgrade, a Solution activated — shows as a banner with a link to its page and what to
  do there, and as a notification in the bell (the shell already has `NotificationBell` and the
  tenant `notifications` table) — rejected: gating again, because an upgrade would lock the console
  of every working tenant at once; and a per-step gating policy, because any Solution could then
  lock a tenant's whole console (D14 lets a package hold only its own pages).
- D8. The registry starts inside Core, and Core registers its own steps through it, so the open shape
  is exercised from the first release. `onboardingStep()` on Foundation's `ConsoleExtensionsInterface`
  comes as a separate item when the first external step exists (the support bot or the SaaS shell) —
  rejected: the Foundation contract in the first phase, because a public contract frozen before its
  first real consumer is shaped by guesses.
- D9. The gate holds required steps only. When the last required step is complete, `onboarded_at`
  is written and the console opens; the wizard still offers the optional steps after it, and leaving
  them is always possible. Core's optional wizard steps in the first version: connect a channel (bot
  token and webhook) and invite the team. An invited colleague therefore never meets the gate.
- D10. An operator's support session passes the gate and sees a banner that the tenant has not
  finished setup — rejected: putting support through the wizard, because the operator would take the
  client's irreversible decisions for them.
- D11. The checklist is a widget on the admin dashboard plus an indicator in the shell that opens the
  list, both read from the same registry; the indicator disappears when everything is done and can be
  hidden by hand — rejected: the dashboard widget alone, because the wizard ends on the assistant and
  the admin dashboard may never be seen; and a permanent menu page, because it occupies the
  navigation forever.
- D13. Steps come in blocks that never interleave, in a fixed order: the SaaS shell's block first
  (it provisions the tenant and owns plan and trial), then Core's, then packages', each package with a
  block of its own. Inside Core's block the steps and their order are a PHP enum — the order is code,
  reviewed like code, not a position number. A contributor orders its own block the same way; nobody
  places a step into another's block — rejected: one global sequence with position numbers, because
  contributors would have to know each other's numbers and a dependency between them ("needs the
  first assistant") could not be expressed safely. Within this order, a Solution's step can rely on
  everything Core's block produced.
- D14. A package's setup does not run at start-up. A package's block runs the first time its own
  page is opened (a Solution's console pages) and holds only that package's pages until its
  required steps are done; the start-up wizard carries only the SaaS block and Core's — rejected:
  every active package's block in the start-up wizard, because it front-loads setup for features
  the tenant may not use yet and makes the first wizard as long as the list of installed packages.
- D15. Constraints the failure hunt put on the gate and the steps:
  - A check that throws counts as complete for that request only; `onboarded_at` is written only on
    a pass where every required check returned true without throwing.
  - The tenant's state is settled only by a full page load of a tenant user — never by an XHR, a
    JSON request or a support session — and written so that concurrent first requests settle it once.
  - The gate redirects page navigations only (GET, Inertia or HTML); JSON and non-GET requests pass,
    the language lock stays enforced on the server where it is today.
  - The gate covers every console stack — `admin`, `builder`, `media` — and on a Solution's pages
    runs before the package's own block (D14).
  - A user without the settings permission sees a waiting page ("your administrator is finishing the
    setup"), never a 403 or a loop.
  - The assistant step offers only the tenant's available languages and defaults to the confirmed
    base language.
  - Derived checks count only what a tenant user did: the platform support user is not "the team".
  - A late required step's banner is read from the registry for the whole tenant; the bell
    notification goes to the users holding the settings permission when it appears, and the banner,
    not the notification, is what later admins see.
- D16. The onboarding gate is not the first gate. The SaaS shell's subscription gate runs before
  it — an expired trial goes to the subscription page first — and a stopped tenant is not held by
  onboarding at all (it has its own banner and refused writes). A tenant reaching the wizard
  therefore always has a plan whose limits allow its required steps: a new tenant is provisioned on
  a plan — rejected: a "blocked" state on steps, because the case it covers does not reach the wizard.
- D7 alternative rejected: grandfathering by any sign of activity (users, active Solutions, changed
  settings) — a heuristic that grows with every new feature; the creation time is one fact.
- D17. Phases 1 and 2 are built on an epic and released once, at the end, as a minor version — no
  phase alone gives a tenant a whole first run. Phase 3 is not part of the epic: it waits for a first
  external step (D8) with no date, so at the epic's finish it moves to its own spec or task —
  rejected: releasing after all three phases, because the release would wait on another project's
  schedule.
- D12. A tenant created already populated (the first-contact path's first-run seed, an import) has an
  assistant when the gate first sees it, so D7 records it as onboarded; the two specs need no
  coordination beyond that.

## Open questions

- Which steps the SaaS shell contributes (plan, billing, trial) and whether its signup form records
  the language step as done — the shell's decision, through the Foundation contract.

## Assumptions left untested

- That the assistant step can reuse the existing assistant creation (service and validation) rather
  than a wizard-only path — taken at normal depth; reading the assistant create controller tests it.
- That the language step can reuse `TenantSettingsEditor` and its lock as they are — taken at normal
  depth; the editor already validates and saves the same fields.
