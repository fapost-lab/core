# Extension documentation for outsiders

Depth: easy — the shape is settled (the published Extending section, rewritten against contracts a
real build used); the reversible calls are taken here as assumptions.

## Idea

Step 7 of the product roadmap, in the owner's words: "Extension documentation for outsiders — the
Extending section rewritten against contracts that survived a real build."

Updated 2026-10-07: written after the UI kit (`ui-foundation`) and after the first Solution
(`first-solution`, whose subject is Feedback); the scaffold command is part of what it describes.

## Goal and problem

- Who is worse off without this, and how: the developer or integrator inside the Laravel ecosystem —
  the platform's primary external audience. They are expected to extend the platform without the
  author's help; today the Extending section describes a surface nobody built against, so the first
  thing they meet is the gap between the documented contract and the real one.
- What is true when the work is done: someone outside the project can read the Extending section,
  build a Solution against it, and not need to read Core's source to get there — measured against
  the gap record that step 6 produced, with every consulted Core file either documented or removed
  as a necessity.

## Stress test

- Hidden assumptions — "this holds only if …":
  - That step 6 produced an honest gap record. Documentation written from a build whose detours
    went unrecorded describes the happy path only.
  - That one Solution's shape generalises. Feedback ships an interface, its own tables and a flow
    trigger, but not a `call` action handler, so that part of the section stays written from
    design rather than from evidence.
  - That Filament is a fair reference point for documentation quality. The brief names it as the
    bar; holding it single-handed is listed as a project risk. It is only a documentation-quality
    benchmark now: Filament is no longer part of what an extension author touches.
- The main trade-off: documenting the contracts as they came out of a real build makes the section
  true today and dates it to today's surface — every later contract change now has a documentation
  cost that did not exist while the section was aspirational.
- The weakest point: there is no outside reader to test it on. Until a real integrator reads it,
  "an outsider can follow this" is an assertion by the people who already know the answer.
- Failure modes — cause, what breaks, the signal that shows it:
  - The section is written from the contracts rather than from the build's record — it is accurate
    and still unusable, because the questions an outsider asks are not the ones the contracts
    answer; the signal is a reader who has to open Core's source anyway.
  - A page ships without being listed in the site navigation — it is simply not published; the
    signal is `mint broken-links` and the absent navigation entry.
  - Documentation restates what belongs in the repository's working material, or the other way
    round — the two drift; `docs/INDEX.md` already forbids the duplication.
- Other shapes considered, and why this one:
  - Documenting the extension surface before building against it — rejected: that is the current
    state, and it is what step 6 exists to correct.
  - A tutorial repository instead of a documentation section — rejected: the published site is
    where an outsider looks first; an example repository can follow, it cannot replace.

## Scope and non-goals

- In scope: the Extending section of the published site (`docs/site/extending/`), rewritten against
  what step 6 learned — the extension model, the contracts an extension actually touches, the
  activation lifecycle as an author meets it, the scaffold command that generates a Solution, and
  the publishing path for a Solution's UI: prebuilt bundles in the package's `dist/`, a composer
  overlay and a derived image, no Node and no rebuild of Core.
- Not doing: the Contributing section (working on Core itself); reference material generated from
  code; the marketplace and distribution story (step 8, still fog); translating the section.

## Decisions

- The section is rewritten from step 6's gap record, not from the contract source — rejected:
  generating it from the code, because the generated reference already exists and is not what an
  outsider is missing.
- It ships as pages under `docs/site/extending/` listed in `docs/site/docs.json` — settled by the
  documentation rules in `AGENTS.md`: a page absent from the navigation is not published, and the
  site deploys from the default branch after merge.
- Written in English, like every published page.
- Changed 2026-10-07: written after the kit and after the first Solution, whose subject is Feedback
  (not HR); the publishing path for UI is prebuilt bundles + composer overlay + derived image, and
  the scaffold command is documented with Feedback as the worked example — superseded: the earlier
  framing of a handler-only first Solution and of Filament resources as the UI path.

## Open questions

- Whether an example Solution repository ships alongside the section — settled by the first-solution
  spec: Feedback is the full worked example, and the scaffold command generates the small starting
  package; what remains is whether Feedback's repository is public as it stands.
- Who reads it as an outsider before it is called done — nobody on the project can.

## Assumptions left untested

- That the Extending section's current structure survives the rewrite and only its content changes —
  taken at easy depth; reading the gap record against the current navigation would test it.
- That one worked example is enough for an integrator to start — taken at easy depth; the first
  outside build tests it.
