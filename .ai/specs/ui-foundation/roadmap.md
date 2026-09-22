# Roadmap — UI foundation for extenders

Destination: the operator-facing surfaces draw from one token source, and a Solution's own
interface composes against a small published set of primitives instead of copying Core's values.

## Phase 1 — One palette, one source

Goal: the duplicate palette stops existing. Done when: the builder and the Filament theme render
from the same token definitions, and no surface defines a colour the other also defines under a
different name.

- [ ] One token source for the operator-facing surfaces, with the builder and Filament theme
      migrated onto it and both surfaces visually unchanged
- [ ] The public site's relationship to that source settled and applied (after: D5 in `spec.md` —
      the answer is what decides whether the site migrates or keeps its own palette)

## Phase 2 — Something to compose against

Goal: an extension can draw an interface that belongs. Done when: a component outside Core imports
the primitives, renders inside the builder, and follows a token change without being touched.

- [ ] A minimal primitive set published through the existing vendor-component contract (after: one
      token source — a primitive that hard-codes values re-creates the drift)
- [ ] fog: the version story for the primitives — what an installed extension is promised when Core
      restyles. It cannot be stated before anything outside Core depends on them.

## Waves

1. One token source with both operator surfaces migrated
2. The public site's decision applied; the primitive set published

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
