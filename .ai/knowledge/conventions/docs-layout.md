---
id: convention-docs-layout
type: convention
status: active
domains: []
paths:
  - "docs/**"
  - README.md
  - CONTRIBUTING.md
summary: "Where documentation lives: published site, working material, plans in specs; records vs plans"
reviewed_at: 2026-10-05
---
# Documentation layout

## Practice

The repository keeps three kinds of documentation apart, and each answers to a different rule.

**The published site.** `docs/site/` holds the Mintlify sources of docs.fapost.in, the single source of
truth for users, operators and contributors. Anything covered there is not restated under `docs/` — link
to it instead. Editing it has its own practice: see `convention-published-docs-updates`.

**Working material under `docs/`.** Roadmap, records and design documents that are never published.
`docs/INDEX.md` is its navigation.

- `docs/roadmap.md` is the living product roadmap: the steps in order, plus an Engineering backlog
  section. It is the one place that says what comes next; the plan of each step or direction lives in
  `.ai/specs/<id>/`, not in the roadmap.
- `docs/platform/ROADMAP.md`, `docs/platform/TASKS.md` and `docs/platform/current-state.md` are
  records. The first two are history of the engineering milestones and of what was implemented, by
  area; do not add plans, milestones or new checkboxes to them. `current-state.md` describes fact, not
  intent: what exists in the code today.
- `docs/reference/` holds design specs and diagrams. They reach agents through stubs in
  `.ai/knowledge/sources/`, which link to them, rather than by being copied into knowledge.
- A plan that has been implemented moves to `docs/archive/`. `docs/platform/plans/` and a separate
  documentation roadmap no longer exist.

**Plans.** Open work is planned in `.ai/specs/` — one spec per product step or direction, each with its
own roadmap of phases — and tracked as Jig tasks. `docs/roadmap.md` orders the steps; it does not hold
their checklists.

Active source-of-truth documentation is written in English. Archive files under `docs/archive/` may keep
their original language until they are deleted or rewritten.

`AGENTS.md` and `CLAUDE.md` hold rules, not inventory: they must not claim that a table, model, job or UI
exists unless that is an architectural rule confirmed by the code.

When code and a document disagree, first establish which it is — stale documentation, a partially built
feature, or a false positive in the reading of the code — and fix the one that is actually wrong.

## Example

The open Forms direction used to live as a checklist in `docs/platform/TASKS.md` and a milestone section in
`docs/platform/ROADMAP.md`. Both now hold one paragraph that points at `.ai/specs/forms-data-collection/`,
where the decisions, phases and open questions actually live; the closed milestones stayed where they were,
because they are history.

## Rationale

Two trackers for the same work drift, and the one an agent happens to read first wins. Keeping plans in
`.ai/specs/` — which `jig context` deliberately never resolves — also stops tomorrow's plan from reaching an
agent working on today's code as if it described the system. The site stays separate again because it is
read by people outside the repository, and a page that contradicts the working notes misleads all of them.
