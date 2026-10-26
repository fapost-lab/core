# Onboarding steps from packages and the SaaS shell

Depth: normal — carried over from `tenant-onboarding` (phase 3), whose epic shipped Core's wizard,
checklist and late-step banner; what is open is the public contract, and it waits for a first real
consumer.

## Idea

The owner's words (2026-10-23, translated), from the `tenant-onboarding` spec: "Later we may add our own
support bot, and we will need to walk the user through it. Then other features may appear, mandatory or
not. So the wizard must be built to extend safely." And (2026-10-24): "First the SaaS settings, then
Core's, then the packages', each with its own settings. They must never interleave. There must not be many
of them, or we tire the user. A package's settings can start the first time its page is opened, so we run
the minimum at the start and the rest for each package as it is needed."

## Goal and problem

- Who is worse off without this, and how: the authors of a Solution (the planned support bot first) and of
  the SaaS shell. Core's step registry (`app/Domains/Onboarding`) is internal: only Core registers a block,
  so a package that needs setup has no way into the wizard or the checklist.
- What is true when the work is done: the SaaS shell adds its block before Core's in the start-up wizard,
  and a Solution's block runs the first time its own page is opened, holding only that Solution's pages.

## Stress test

- Carried from `tenant-onboarding`: steps come in blocks that never interleave, ordered Shell, Core,
  Package (`OnboardingOrigin`); a step is a page plus a check; a required step must be completable by the
  tenant's administrator alone; the gate fails open (`adr-20261023-onboarding-state-and-gate`,
  `domains/onboarding/RULES.md`).
- The weakest point: the SaaS shell is not a Solution. Foundation's `ConsoleExtensionsInterface` binds every
  declaration to the Solution whose provider is booting and closes after boot, so the shell's block needs
  another way in.
- Failure mode: a package block that gates its own pages has a step whose check is wrong — the package's
  pages are held, or let through early; the same fail-open rule as Core's gate applies.

## Scope and non-goals

- In scope: the Foundation contract for registering a block, a way in for the SaaS shell, a package's
  block on the first opening of its page, the Extending docs page.
- Not doing: the support-bot Solution itself; the SaaS shell's own steps (the shell's decision).

## Decisions

- Inherited D8 (`tenant-onboarding`): the contract is not frozen before its first real consumer; this spec
  waits for the support bot or the SaaS shell to need a step.
- Inherited D13: blocks never interleave, Shell, Core, Package; an owner orders its own block in code.
- Inherited D14: a package's setup does not run at start-up; its block runs the first time its page is
  opened and holds only its own pages.

## Open questions

- Which steps the SaaS shell contributes (plan, billing, trial) and whether its signup form records the
  language step as done — the shell's decision.

## Assumptions left untested

- That a package's block can reuse Core's wizard shell and checklist unchanged — tested when the first
  external block is built.
