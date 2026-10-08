# Roadmap — Ecosystem distribution

Destination: a third-party extension reaches an installation its author does not own, through a
channel we chose deliberately, with what it may do there bounded by something stronger than trust.

## Phase 1 — Lift the fog

Goal: the question becomes answerable. Done when: the four unknowns below have answers written
down, and the decision to build a channel — or to declare Composer the channel and stop — is taken.

- [ ] fog: what an extension may do at runtime and where the sandbox boundary sits. It cannot be
      stated before the activation surface is fixed (after: the activation lifecycle spec — the
      boundary is drawn around what activation grants)
- [ ] fog: whether distribution rides on the existing package channel or needs its own, and what a
      paid closed extension needs in order to be installable. It cannot be stated before an
      outsider's build shows which contracts are actually touched (after: the first-solution spec)
- [ ] The recon pass written up as a decision: the channel, the boundary, and what is not being
      built

- [ ] `plugin-runtime-contract-idea` — Think through a runtime plugin contract without Composer (ADR-06: plugins install from a zip without a deploy) with `jig-idea`, as an input to the recon pass

## Phase 2 — Build whatever the pass chose

Goal: extensions move. Done when: an extension authored outside the project is installed into an
install nobody involved in this repository operates.

- [ ] fog: the mechanism itself. It is deliberately unsized — the recon pass decides whether this
      is a registry and a store, or a paragraph of documentation about Composer

## Waves

1. The two fog items, once their blockers are done
2. The recon write-up
3. Whatever the write-up chose

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
