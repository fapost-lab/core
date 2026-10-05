# Roadmap — Extension documentation for outsiders

Destination: a developer outside the project builds a Solution from the published Extending section
alone, without reading Core's source.

## Phase 0 — Start from a working package

Goal: a new extension package starts from proven plumbing instead of being assembled by hand. Done
when: a package created from the skeleton installs into a Core checkout through the composer
overlay, its CI passes against Core's main, and its README alone explains how to develop it.

- [ ] Extension skeleton — a public template repository (`fapost-lab/extension-skeleton`, Apache-2.0) with the plumbing proven on the SaaS package: a service provider found by package discovery, tests that boot the real Core, CI that installs the package on Core's main through the overlay and proves Core still boots without it, Pint, and a README on developing it; the Extending docs link to it
- [ ] Skeleton gains Solution examples — the skeleton shows the contracts a Solution implements (handlers, manifest, activation) as the first Solution used them (after: the first-solution spec — the contracts take their shape from that build, a skeleton written earlier would guess)

## Phase 1 — Write it from the evidence

Goal: the section describes the surface a real build used. Done when: every contract the first
Solution touched is documented, and every Core source file that build had to consult is either
covered by a page or recorded as a contract still missing.

- [ ] The Extending section rewritten against the first Solution's gap record — extension model,
      the contracts an author touches, activation as an author meets it (after: the first-solution
      spec's gap record — writing before it describes the design, not the surface)
- [ ] The worked example an author follows end to end, published and listed in the site navigation

## Phase 2 — Prove it on someone

Goal: the claim "an outsider can follow this" stops being self-assessed. Done when: someone who did
not build the platform follows the section to a working Solution, and what they got stuck on is
fixed.

- [ ] fog: the outside reader — who they are and what counts as them succeeding. It cannot be
      stated before the section exists to hand them.

## Waves

1. Extension skeleton
2. The Extending section rewritten against the first Solution's gap record; The worked example an author follows end to end, published and listed in the site navigation; Skeleton gains Solution examples
3. fog: the outside reader

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
-->
