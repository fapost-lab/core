# Roadmap — Tenant onboarding

Destination: a new tenant's first administrator cannot miss the irreversible decisions, lands on a
working assistant, always sees what is left to set up, and Core, Solutions and the SaaS shell add
their own steps without touching each other's code.

Epic: epic/tenant-onboarding
Release: minor

## Phase 1 — A new tenant cannot miss the language

Goal: the language trap is closed for every new tenant. Done when: on a freshly provisioned tenant
the first administrator is held in the wizard until the languages are confirmed and a first
assistant exists, then lands on that assistant; an existing tenant is never held.

- [ ] The wizard holds a new tenant until the content languages are confirmed — the step registry in
      Core, the tenant's onboarding state and the one-time decision (D7), the gate with its
      allow-list built from step routes, support sessions passing it (D10), a contributor's failing
      check treated as complete and logged, the wizard shell with progress, and the languages step
      as the first registered step, its copy saying what locks and when
- [ ] The first-assistant step, and the redirect to that assistant when the wizard ends (after: the
      wizard — the step registers through the registry and runs inside the shell it builds)

## Phase 2 — What is left is always visible

Goal: after the gate, nothing is forgotten. Done when: optional steps can be skipped with a pointer
to where they live, the checklist shows done and left from anywhere in the console, and a required
step that arrives later reaches a working tenant without locking it.

- [ ] Optional steps in the wizard — skip with "set this up later in <menu section>", and Core's two:
      connect a channel and invite the team (after: the wizard — optional steps run in its shell)
- [ ] The checklist: a dashboard widget and a shell indicator, both read from the registry, hidden
      when done or by hand (after: the wizard — the checklist lists the registry's steps)
- [ ] A required step that appears after onboarding reaches the tenant as a banner with a link and a
      bell notification (after: the wizard — "after onboarding" is the state it records)

## Phase 3 — Others contribute steps

Goal: the step list is open beyond Core. Done when: the SaaS shell adds its block before Core's in
the start-up wizard, and a Solution's block runs the first time its page is opened, holding only that
Solution's pages.

- [ ] `onboardingStep()` on Foundation's `ConsoleExtensionsInterface`, blocks per contributor in the
      fixed order (D13), a way in for the SaaS shell, which is not a Solution and cannot use the
      Solution-bound registry, the Extending docs page for it (after: a first external step exists — the
      support-bot Solution or the SaaS shell; D8 keeps the contract unfrozen until then)
- [ ] A package's block on the first opening of its page, holding only its own pages (D14) (after:
      the Foundation contract — a package can only register its block through it)
- [ ] fog: the SaaS shell's own steps, and whether signup records the language step as done

## Waves

1. The wizard with the languages step
2. The first-assistant step
3. Optional steps, the checklist, the late-step banner
4. The Foundation contract
5. Package blocks on first opening

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
- A wave entry names an item by its title (the text before its first ` — `) or its task id,
  entries separated by `;` — `jig spec plan` reports an entry that names no item or several.
-->
