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

### Planning — what is still to be done

- [`../.ai/specs`](../.ai/specs) — **the plans.** One spec per step or direction: idea, decisions, open
  questions and a roadmap of phases. Listed with `.ai/scripts/jig spec list`.
- [`idea-brief.md`](./idea-brief.md) — the owner's product brief: problem, users, scope, positioning.
- [`roadmap.md`](./roadmap.md) — **the living roadmap**: the product steps in order, the engineering
  backlog that runs beside them, and which spec or task carries each one.
- `.ai/scripts/jig status` — the active Jig tasks: the current operational focus.

### Records — what was already done

- [`platform/current-state.md`](./platform/current-state.md) — what exists in code today.
- [`platform/ROADMAP.md`](./platform/ROADMAP.md) — history of the engineering milestones.
- [`platform/TASKS.md`](./platform/TASKS.md) — what was implemented, by area.

### Design documents linked into agent knowledge

Each of these is linked by a stub in [`../.ai/knowledge/sources`](../.ai/knowledge/sources), which is how
`jig context` hands it to an agent working on the matching code. They describe the code as built; when
code and document disagree, verify against the code and fix the document.

- [`platform/architecture/adr`](./platform/architecture/adr) — architecture decision records. A record
  whose body no longer matches the code opens with a "Superseded in part" note.
- [`platform/architecture/platform`](./platform/architecture/platform) — domain notes: staff, assistants,
  contacts, concurrency, flow versioning, the message pipeline, logging and retention.
- [`platform/runtime/flow`](./platform/runtime/flow) — writing node handlers: development guide,
  cookbook, test matrix, config conventions, registered nodes.
- [`reference/specs`](./reference/specs) — Flow Engine and node specs, builder variable storage, the
  conversation transcript decision record.
- [`reference/diagrams`](./reference/diagrams) — Mermaid sources: webhook pipeline, engine loop, tenant
  context, session state machine, subflow lifecycle.

### Agent knowledge

- [`../.ai/knowledge`](../.ai/knowledge) — architecture, rules, glossary, per-domain packs and ADRs that coding
  agents load through Jig (`.ai/scripts/jig context`). Written for implementers, not published.

### Archive

- [`archive`](./archive) — implemented plans, superseded decisions and old Notion exports. Kept in their
  original language; not maintained.

## Rules

- If a subject is on the site, do not restate it here — link to it.
- Open work is planned in `.ai/specs/` and tracked as Jig tasks; `roadmap.md` orders them and names
  every open spec once. `platform/ROADMAP.md` and `platform/TASKS.md` are records of what was done; do
  not add plans or new checkboxes to them.
- An implemented plan moves to `archive/` once its rules live in `.ai/knowledge/`.
- `platform/current-state.md` describes code reality, not intended architecture.
- Active documentation is written in English; a document still in Russian is translated the next time
  it is corrected. The archive keeps its original language.
- `CLAUDE.md` holds agent rules and stable constraints; it is not roadmap documentation.
- When code and a document disagree, verify against the code and fix the document.

## Generated API docs

`composer run docs:build` regenerates the Doctum PHP API reference into `public/core`. It covers every class
in the repository; the site covers the ones extensions may depend on.
