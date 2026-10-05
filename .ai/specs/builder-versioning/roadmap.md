# Roadmap — Builder versioning and content

Destination: an author can preview a draft, compare and roll back versions, and edit content by key
from the builder.

## Phase 1 — Versions are recoverable

Goal: a bad publish is undone in the builder. Done when: an author restores an earlier version into
the draft and publishes it, and can see the diff between any two versions first.

- [ ] Rollback: restore an earlier published version into the draft
- [ ] Compare two versions

## Phase 2 — Try before publishing

Goal: a draft can be exercised without reaching a real contact. Done when: an author runs a draft in
the builder and sees the transcript it would produce, with no message sent to a channel.

- [ ] fog: Preview a draft — sandbox sender or simulated transcript is undecided (open question in `spec.md`)

## Phase 3 — Content and storage ergonomics

Goal: text and variables are managed at scale. Done when: an author jumps from a node to its text
in the Content tab and renames a variable across a flow in one action.

- [ ] Content-key store with "Open in Content" from a node
- [ ] Bulk variable rename
- [ ] Per-node logging override and a "Logged" column in the session list
- [ ] fog: attribute groups deeper than one level — no rollout has needed it
- [ ] fog: contact attribute groups as managed objects — definitions table, cross-flow consistency, archiving, discovery API and a report builder; no rollout has asked for them

## Waves

1. Rollback: restore an earlier published version into the draft; Content-key store with "Open in Content" from a node
2. Compare two versions; Bulk variable rename
3. Per-node logging override and a "Logged" column in the session list

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
