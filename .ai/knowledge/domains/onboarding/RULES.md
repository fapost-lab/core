---
id: rule-onboarding
type: rule
status: active
summary: What holds the console of a new tenant in the setup wizard, who writes its state, and what the gate must let through
domains:
  - onboarding
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Onboarding/**"
  - "app/Infrastructure/Onboarding/**"
  - app/Http/Middleware/EnsureTenantOnboarded.php
  - "app/Http/Controllers/Admin/Onboarding*.php"
  - app/Console/Commands/Ops/TenantsOnboardingRestartCommand.php
  - "tests/Feature/Onboarding/**"
  - "resources/js/console/shell/OnboardingChecklist*.vue"
reviewed_at: 2026-10-25
---
# Onboarding rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is broken.

## Invariants

- **Only a tenant that provisioning put into the wizard is ever held.** Existing tenants are `complete` from the
  settings migration; provisioning writes `pending`; a seeder or import of a populated tenant writes `complete`
  (`adr-20261023-onboarding-state-and-gate`). Enforced:
  `OnboardingStateTest::test_a_tenant_that_existed_when_the_settings_migration_ran_is_onboarded`,
  `test_provisioning_puts_the_new_tenant_into_the_wizard`,
  `EnsureTenantOnboardedTest::test_a_tenant_that_was_never_put_in_the_wizard_is_not_held`.
- **The wizard is the only writer of the state; the gate only reads.** `complete` is written only when every
  required step is done and no check threw (`uncertain`). Why: a gate that decides races the bell's polling and
  support sessions, and a thrown check would become a permanent "onboarded". Enforced:
  `OnboardingWizardTest::test_a_check_that_throws_is_counted_complete_but_never_closes_the_wizard`,
  `test_a_clean_all_done_tenant_is_closed_by_the_redirect_chain`.
- **The gate fails open** — a deliberate exception to `conventions/runtime-settings.md`'s "a setting that guards
  access fails closed": unreadable settings count as not pending, and a throwing check counts as done for the
  request. Why: the gate guards a first-run convenience, not access, and failing closed would lock every tenant out
  on a half-migrated deploy or a contributor's bug. Enforced:
  `EnsureTenantOnboardedTest::test_a_tenant_whose_state_cannot_be_read_is_not_held`.
- **A pending tenant is held only on page navigations of its own staff.** The gate lets through non-GET requests
  and JSON without `X-Inertia`, a platform support user (who sees a banner instead), a stopped tenant (it has its
  own banner and the SaaS gates run first), the wizard's routes, sign-out, the locale switch and every route a
  registered step lists in `routes()`; an Inertia visit gets `Inertia::location`. It sits in `admin` after
  `EnsureCanAccessPanel` and in the builder and media stacks. Enforced: `EnsureTenantOnboardedTest`,
  `ConsoleRoutesArchitectureTest`.
- **A complete tenant costs the gate one settings read, and a page builds the steps once.** Progress — every step's
  check — runs only for a pending tenant; the gate of a complete tenant builds no step. `OnboardingProgress` (scoped)
  builds the step instances once per request or job and never memoizes their answers; the state is still read fresh by
  each consumer (one read each: the gate, the late-step and the checklist props), never cached, as the ADR asks. A full
  page of a complete tenant builds the steps once for the late-step keys; a tenant that went through the wizard with the
  checklist not hidden also runs every check. The props are closures, so partial reloads (the bell's polling) pay none
  of it. Enforced: `EnsureTenantOnboardedTest::test_an_onboarded_tenant_does_not_ask_the_steps_anything` (no check,
  one build per request), `test_the_gate_alone_builds_no_step_for_an_onboarded_tenant`,
  `test_the_steps_are_built_once_per_scope`.
- **The wizard's state has one writer; announcements have their own.** `OnboardingSettings` (state, confirmations,
  `known_steps`) is written only through the wizard: `OnboardingWizard::close()` — also the entry for a seeder or
  import that creates a populated tenant (`close(populated: true)`) — and `OnboardingState::start()`. Which late steps reached the bell lives in
  a separate group, `OnboardingAnnouncementSettings` (`onboarding_announcements`), written only through
  `OnboardingAnnouncements` and cleared by `start()`. Why: spatie saves every property of a class, so a page load
  writing into `OnboardingSettings` could save a stale `state` over an operator's restart. Enforced:
  `OnboardingLateStepTest::test_announcing_never_writes_the_wizards_state`,
  `test_starting_the_wizard_again_forgets_the_known_and_announced_steps`.

## Rules

- **Blocks never interleave.** Steps come in blocks, ordered by `OnboardingOrigin` (`Shell`, `Core`, `Package`);
  an owner orders its own block in code (Core: the case order of `CoreOnboardingStep`), and nobody places a step
  in another's block. Why: a contributor cannot know another's numbering, and a step may rely on everything an
  earlier block produced. Enforced: `OnboardingRegistryTest`.
- **The domain knows no concrete step.** `Domains/Onboarding` depends only on the Tenancy contracts; Core's steps,
  which read Assistant and Flow, live in `Infrastructure/Onboarding`. Enforced: `OnboardingBoundariesTest` (PHPat,
  runs through phpstan).
- **A required step must be completable by the tenant's administrator alone** — inside the tenant, with no
  operator, payment or external account. A step that cannot be done holds the console forever. Review only.
- **A step's completion comes from data where it can.** A step that cannot be derived records a confirmation in
  `confirmed_steps`; a restart forgets confirmations and keeps what the data shows. Review only.
- **Optional steps come after the wizard closes and write no state.** The gate holds required steps only (spec D9):
  when the last required step is done the wizard writes `complete` and only then offers the optional steps that are
  still open (Core: `channel`, `team`), landing in the console after the last. Their pages work for any tenant that
  is not pending and send a pending one back to the wizard's entry. Skipping is `console.admin.onboarding.continue`,
  which records nothing: a skipped step stays open, by its data, for the checklist (D6a). Why: a recorded skip would
  be a second state with a second writer, and an optional step must never hold the console. A step that cannot be
  done for this user (no assistant, a limit reached) is passed over, not shown. Enforced:
  `OnboardingOptionalStepsTest::test_skipping_walks_on_to_the_console_and_writes_nothing`,
  `test_the_optional_pages_send_the_person_back_while_a_required_step_is_open`.
- **The team is the tenant's own people.** A derived check counts what the tenant did: the platform support user is
  never "the team" (`TeamStep`, `User::withoutPlatformSupport()`). Enforced:
  `OnboardingOptionalStepsTest::test_the_platform_support_user_is_not_the_team_but_an_invitation_is`.
- **Each step's page authorizes its own permission** (`adr-20261023-console-entry-by-assignment`); staff without
  `ManageSettings` see the waiting page, never a 403. Enforced:
  `OnboardingWizardTest::test_staff_who_may_not_manage_settings_see_the_waiting_page`.
- **Restarting is an operator's action**: it holds a working tenant's console until the required steps are done,
  so the command and every caller of `TenantOnboardingInterface` ask first. Enforced: `CoreTenantOnboardingTest`.
- **The checklist is only for a tenant that went through the wizard.** It shows when `started_at` is set
  (provisioning or a restart), the wizard is closed, a step is still open, and the user holds `ManageSettings`; never
  to the platform support user. A tenant that existed before the wizard never gets one — its `confirmed_steps` is
  empty, so "Content languages" would stay open forever. Its items are every registered step, optional ones included,
  linking to the step's wizard page; those pages, once the wizard is closed, send the person to where the thing lives
  (assistants list, tenant settings) rather than to the dashboard. A tenant a seeder or import populates is closed with
  `OnboardingWizard::close(populated: true)`, which forgets the `started_at` provisioning wrote, in the same write: it was
  never walked through, so it is treated like a tenant from before the wizard and gets no checklist. Confirming the
  languages for it instead was rejected: it would know only Core's confirmation step and mark as seen what nobody saw.
  Enforced: `OnboardingChecklistTest`, `test_a_populated_tenant_that_provisioning_had_started_gets_no_checklist`.
- **Hiding the checklist is per user and remembers the registry.** Hiding stores the step keys registered at that
  moment on the user (`users.onboarding_checklist_hidden`); it hides the shell indicator and the dashboard widget, and
  the checklist returns by itself when a step outside that list is registered. Enforced:
  `OnboardingChecklistTest::test_hiding_is_per_user_and_a_new_step_brings_the_checklist_back`.
- **A late step is a required step not in `known_steps`.** Closing the wizard records every registered key; the
  settings migration gives existing tenants Core's keys. A late step that is open shows a banner to the whole tenant
  (platform support included) with a link to its page, and is announced once in the bell of the tenant's active staff
  holding `ManageSettings` — never the platform support user — by the first full page load after it appeared, under a
  per-tenant cache lock. Admins who arrive later see the banner, not a notification. A known step that reopened from
  the data (the last assistant deleted), or a late optional step, shows only in the checklist. A late check that
  throws counts as done and is logged. Enforced: `OnboardingLateStepTest`.

## Known limits

- **Announcements are written after delivery.** A notification that throws mid-loop leaves the step unannounced, so
  the next load may notify earlier recipients again (at-least-once, not at-most-once).
