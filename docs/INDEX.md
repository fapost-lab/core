# FaPost Documentation Index

**Published documentation lives at [docs.fapost.in](https://docs.fapost.in) and is the single source of
truth.** Its source is `docs/site/` in this repository; a push to the deploy branch republishes the site.

What remains under `docs/` is working material: drafts, plans, specs still being designed, and records.
None of it is published, and none of it overrides the site. Where a subject is covered on the site, the
document here has been removed rather than left to drift.

## Published — go to the site

| Subject | Where |
|---|---|
| What FaPost is, packages, licence | [Introduction](https://docs.fapost.in/) |
| Using the product | [Using FaPost](https://docs.fapost.in/using/concepts) |
| Installing and operating | [Self-Hosting](https://docs.fapost.in/self-hosting/overview) |
| Building a Solution or Plugin | [Extending](https://docs.fapost.in/extending/extension-model) |
| Working on Core itself | [Contributing](https://docs.fapost.in/contributing/local-setup) |
| Contracts, schemas, queues, commands | [Reference](https://docs.fapost.in/reference/foundation-contracts) |
| Licence, CLA, trademark | [Legal](https://docs.fapost.in/contributing/legal) |

Editing the site: change the MDX under `docs/site/`, preview with `cd docs/site && mint dev`, and open a
pull request. Navigation is defined in `docs/site/docs.json` — a page absent from it does not appear, and an
entry pointing at a missing file fails the build.

## Working material — stays here

### Status and planning

- [`platform/TASKS.md`](./platform/TASKS.md) — the only implementation checkbox tracker.
- [`platform/ROADMAP.md`](./platform/ROADMAP.md) — milestones and dependencies.
- [`platform/current-state.md`](./platform/current-state.md) — what exists in code today.
- [`platform/PROJECT.md`](./platform/PROJECT.md) — short project context.
- [`platform/plans`](./platform/plans) — implementation plans and brownfield audits.
- [`../drafts/CURRENT_TASK.md`](../drafts/CURRENT_TASK.md) — current operational focus for agents.

### Records

- [`platform/architecture/adr`](./platform/architecture/adr) — architecture decision records, including
  superseded ones. Internal: the constraints they impose are published on the site in their own words, the
  records themselves are not.

### Specs and diagrams

- [`reference/specs`](./reference/specs) — auth, builder, Flow Engine, and messaging specs. Design documents
  for implementers, not product documentation.
- [`reference/diagrams`](./reference/diagrams) — Mermaid sources.

### Archive

- [`archive`](./archive) — historical documents and old Notion exports. Kept in their original language
  until deleted or rewritten.

## Rules

- If a subject is on the site, do not restate it here — link to it.
- `platform/TASKS.md` is the only status tracker. No status tables anywhere else.
- `platform/current-state.md` describes code reality, not intended architecture.
- Published documentation is written in English. Working material and archive may be in any language.
- `drafts/` contains only `CURRENT_TASK.md`.
- `CLAUDE.md` holds agent rules and stable constraints; it is not roadmap documentation.
- When code and a document disagree, verify against the code and fix the document.

## Generated API docs

`composer run docs:build` regenerates the Doctum PHP API reference into `public/core`. It covers every class
in the repository; the site covers the ones extensions may depend on.
