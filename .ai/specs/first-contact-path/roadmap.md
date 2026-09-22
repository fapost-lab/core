# Roadmap — First-contact path

Destination: someone who has never seen FaPost goes from a clean machine to an assistant answering
their message, inside a time the project has named out loud.

## Phase 1 — The path exists

Goal: a fresh install reaches a working assistant without project knowledge. Done when: on an empty
database, one documented sequence produces a tenant, an assistant and a flow that answers a message.

- [ ] A first-run seed that works on an empty install — tenant, assistant and a flow that answers —
      rather than only filling a tenant that already exists
- [ ] The install-to-answer sequence walked end to end on a clean machine, with each place it dies
      fixed or documented (after: the first-run seed — there is no sequence to walk until the seed
      produces something that answers)

## Phase 2 — The path is measured

Goal: "fast to start" becomes a number. Done when: the time from a clean machine to a first answer
is measured on that path and published as the target.

- [ ] The target time named and the path measured against it (after: D2 in `spec.md` — a
      measurement without a committed number is a stopwatch reading, not a check)

## Phase 3 — The path is what the reader is given

Goal: the published documentation is the path, not a description of the services. Done when: the
Self-Hosting section walks the same sequence, and its troubleshooting page answers the failures the
walk actually hit.

- [ ] The Self-Hosting section rewritten around the walked path, with the failures it hit answered
      where a reader meets them (after: the walk — pages written before it document the intended
      path, not the real one)

## Waves

1. The first-run seed
2. The clean-machine walk
3. The named target and the documentation rewrite

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
