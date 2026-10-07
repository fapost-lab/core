# Builder versioning and content

Depth: easy — the shape was drawn in the builder page-structure spec and left as "later"; this
carries it into Jig without new design.

## Idea

Collected on 2026-10-05 while reconciling the old documentation with the code (owner's request: move
all old documentation into Jig and come out with a complete roadmap). The builder page-structure
spec and the flow constructor data-storage spec (both archived under `docs/archive/reference/specs/builder/`)
named Preview, Compare versions, Rollback, a content-key store and a storage UX backlog as later work.

## Goal and problem

- Who is worse off without this, and how: a flow author who publishes a broken version has no
  rollback except republishing by hand, cannot see what changed between versions, and cannot try a
  flow without publishing it.
- What is true when the work is done: an author can preview a draft, compare two versions and roll
  back to an earlier one from the builder; content is edited by key with a jump from a node to its
  text.

## Stress test

- Hidden assumptions — "this holds only if …": rollback is safe only if running sessions keep the
  version they started on — they do (`flow_sessions.flow_definition_id`, `restrictOnDelete`).
- The main trade-off: preview fidelity (run real handlers against a sandbox contact) against safety
  (no message must leave to a real channel from a preview).
- The weakest point: preview — sending is inline in handlers, so a sandbox needs a sender that
  records instead of delivering.
- Failure modes — cause, what breaks, the signal that shows it: rollback publishing a version whose
  handler version was removed fails at publish; the signal is a registry miss on `type@version`.
- Other shapes considered, and why this one: rollback as "copy old version into the draft" (an
  author then publishes) — kept as the likely first slice, it reuses publish validation.

## Scope and non-goals

- In scope: rollback, compare versions, preview, a content-key store with "Open in Content" from a
  node (today the Content tab derives entries from node config such as `<node>.text`), the storage
  UX backlog (per-node logging override, attribute groups deeper than one level, bulk variable
  rename, a storage-mode tooltip), and a "Logged" column in the session list.
- Contact attribute groups, deferred by the V1 flow-engine design and kept here because the builder
  is where they are authored: an `attribute_group_definitions` metadata table, cross-flow group
  consistency validation, a group archiving lifecycle, a report builder over groups, and a group
  discovery API (the old sketch: a Redis `tenant:{id}:contact_groups` cache refreshed on flow save,
  report SQL over contact attributes, an index on them). Nothing of this exists today; groups are
  plain prefixes in contact attribute paths, guarded at runtime by `ContactWriter`.
- Not doing: the expression-engine choice (spec `flow-engine-v1x`).

## Decisions

- None yet beyond the archived design.
- Changed 2026-10-07: the "Logged" column goes on the kit's session list (after `ui-foundation`
  phase 2), not on the Filament FlowSessions resource — Core's operator UI leaves Filament and no
  new screen is built on it.

## Open questions

- Preview against a sandbox sender, or a simulated transcript without handlers — decides the
  preview item's size.

## Assumptions left untested

- That authors want compare before preview — order taken from the archived spec, untested.
