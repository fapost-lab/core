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
reviewed_at: 2026-10-24
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
- **A complete tenant costs the gate one settings read.** Progress — every step's check — runs only for a pending
  tenant. Enforced: `EnsureTenantOnboardedTest::test_an_onboarded_tenant_does_not_ask_the_steps_anything`.

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
