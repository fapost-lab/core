# Roadmap — Onboarding steps from packages and the SaaS shell

Destination: an extension package or the SaaS shell adds its own onboarding block without touching Core,
in its fixed place, and a package's block runs only when its page is first opened.

## Phase 1 — Others contribute steps

Goal: the step list is open beyond Core. Done when: the SaaS shell adds its block before Core's in the
start-up wizard, and a Solution's block runs the first time its page is opened, holding only that
Solution's pages.

- [ ] `onboardingStep()` on Foundation's `ConsoleExtensionsInterface`, blocks per contributor in the fixed
      order (D13), a way in for the SaaS shell, which is not a Solution and cannot use the Solution-bound
      registry, the Extending docs page for it (after: a first external step exists — the support-bot
      Solution or the SaaS shell; D8 keeps the contract unfrozen until then)
- [ ] A package's block on the first opening of its page, holding only its own pages (D14) (after: the
      Foundation contract — a package can only register its block through it)
- [ ] fog: the SaaS shell's own steps, and whether signup records the language step as done

## Waves

1. `onboardingStep()` on Foundation's `ConsoleExtensionsInterface`, blocks per contributor in the fixed
   order (D13), a way in for the SaaS shell, which is not a Solution and cannot use the Solution-bound
   registry, the Extending docs page for it
2. A package's block on the first opening of its page, holding only its own pages (D14)

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
