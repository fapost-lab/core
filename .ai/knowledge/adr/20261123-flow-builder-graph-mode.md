---
id: adr-20261123-flow-builder-graph-mode
type: adr
status: proposed
date: 2026-11-23
domains:
  - flow-builder
  - staff
paths:
  - "resources/js/builder/components/editor/graph/**"
  - resources/js/builder/utils/graphLayout.ts
  - resources/js/builder/utils/graphFrames.ts
  - app/Http/Controllers/Builder/BuilderPreferenceController.php
  - app/Domains/Staff/Enums/BuilderMode.php
  - "database/migrations/tenant/*builder_mode*"
  - app/Http/Requests/UpdateBuilderPreferenceRequest.php
summary: Why the builder's graph mode only redraws the draft and edits through builderStore's existing operations with automatic ELK layout and no stored positions, and why the tree/graph choice is the users.builder_mode column
reviewed_at: 2026-11-23
---
# The builder's graph mode is a second view over the builder store, laid out automatically, and the choice of mode is a column on the staff user

## Context

Spec `flow-automations`, phase 8, asked for a graph builder mode: "editing on the graph component, the choice kept
per person", after the run graph page (`resources/js/console/run-graph`, Vue Flow + ELK) had drawn a flow as cards
and nested branch frames. The builder's definition is a graph without coordinates (`FlowDefinition {nodes, edges}`),
and every structural edit goes through `builderStore`, which also heals its tree invariants (`loop`/`loop_end` pairs,
branch ends, the terminal `end`) and carries undo, autosave and the read-only mode. The next roadmap item counts how
many people use each mode from SaaS, so where the choice lives is a contract. Approved at the T3 gate by the owner
("Да, всё по рекомендациям", 2026-11-23: the recommended option of every decision of the task's design).

## Decision

- **A second view, not a second editor.** Graph mode (`resources/js/builder/components/editor/graph/`) draws the whole
  draft on one Vue Flow canvas — branches of `condition`, `branch` and button `send_message` nodes as nested frames —
  and changes it only through the store's existing operations: "+" on an edge or under a card's free handle opens
  `NodePalette` → `insertNode(source, handle, …)`, delete and move open the tree's `DeleteNodeDialog` and
  `MoveNodeDialog`, a click selects the node for the one `ConfigPanel` both modes share. `definitionGraph.ts` is a pure
  drawing of the definition and never writes it.
- **The layout is automatic.** ELK lays the drawing out from its structure on every structural change; nodes are not
  dragged, edges are not drawn by hand, and no position is stored in the definition. The view is fitted once, on the
  first layout.
- **The shared drawing code belongs to the builder.** `@builder/utils/graphFrames.ts` (branch frames) and
  `@builder/utils/graphLayout.ts` (ELK) serve both the builder and the console's run graph; the console imports them,
  the builder never imports the console. Both load ELK and Vue Flow only in lazy chunks.
- **The choice is the person's.** `users.builder_mode` (tenant schema, `string(16)` nullable, `null` = tree), the enum
  `App\Domains\Staff\Enums\BuilderMode` (`Tree`, `Graph`), written by `PUT /builder/preferences` for the signed-in
  user only (authorized with the class-level `viewAny` on `FlowDraft`, like the builder's option lists) and read into
  the builder payload as `flow.builderMode`. Switching is immediate; a refused save (a stopped tenant answers 423) keeps
  the switch for the page. The mode is never part of the draft, the undo stack or the dirty flag.
- **Subflows are not expanded**: a `subflow` card links to the flow it starts; the child is another draft.

## Alternatives

- **A free canvas** — dragging nodes, drawing edges by hand, stored positions. Needs position fields in
  `FlowDefinition` (drafts and published versions), validation of arbitrary edges against the store's tree invariants,
  and an answer to what the tree does with coordinates. Rejected for the first version; it can be built on top of this.
- **A graph for viewing only**, editing back in the tree. Does not meet "editing on the graph". Rejected.
- **The choice in `localStorage`.** Kept per browser, not per person, and invisible to the operator who counts the
  modes. Rejected. **A general `users.preferences` json column.** Wider than needed, no second consumer; the project
  keeps one column per personal setting (`locale`, `pinned_applications`, `onboarding_checklist_hidden`). Rejected.
- **The builder imports `@console/run-graph/*`.** A builder → console dependency that does not exist today. **A new
  shared `resources/js/graph/` folder.** A new base folder. Both rejected.

## Consequences

- One definition format and one set of invariants: undo, autosave, read-only and templates work in both modes
  unchanged; removing the graph mode is removing a component and a column.
- Reordering on the graph goes through the move dialog; there is no drag-and-drop.
- A free canvas later must change the definition format on purpose — it supersedes this record, it does not slip in.
- A count of modes per tenant is a `group by builder_mode` over `users`, `null` read as tree.
