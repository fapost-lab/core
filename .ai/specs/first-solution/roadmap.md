# Roadmap — First solution built through the public contracts

Destination: a Solution package from its own repository is installed, activated and doing real work
in a tenant's flow, with every contract gap it hit recorded and closed outside Core.

## Phase 1 — The package exists and is honest

Goal: a Solution that an outsider could have written. Done when: the package builds and installs
with no import of `App\…` and no change made to Core for its benefit.

- [ ] The Solution package scaffolded in its own repository against Foundation and Support, with a
      manifest the platform accepts (after: the activation lifecycle spec — there is nothing to
      declare a manifest to until activation exists)
- [ ] The two action handlers doing real work in a flow through a `call` node, exercised end to end
      on an install where the Solution was activated, not wired in

## Phase 2 — The gaps become contract work

Goal: what the build hit is fixed where it belongs. Done when: each gap is either a merged
Foundation change or a written-down decision not to close it.

- [ ] The gap record: every missing contract, workaround and consulted Core source file, in one
      place that step 7 can be written from (after: the handlers working — a gap list assembled
      before the build is a guess)
- [ ] The contract additions the build needed, made in the Foundation repository and consumed by
      the Solution from the published package rather than the development symlink

## Waves

1. The package and its handlers
2. The gap record; the Foundation contract additions

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
