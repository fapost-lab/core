# Documentation Roadmap

This file defines how `docs/` should be maintained as the repository documentation.

## Documentation Layers

- `docs/` - tracked developer documentation.
- `docs/INDEX.md` - navigation source of truth for the full documentation set.
- `docs/platform/TASKS.md` - record of what was implemented, by area; not a plan.
- `.ai/specs/` - the plans for open work, one spec per step or direction.
- `.ai/scripts/jig status` - current operational focus: the active Jig tasks.
- `CLAUDE.md` - agent rules and stable coding/architecture constraints, not roadmap/status documentation.

## Maintenance Rules

1. Keep `docs/platform/current-state.md` aligned with code, especially when a task moves from planned to partial or done.
2. Keep open work in `.ai/specs/` and in Jig tasks; `docs/platform/TASKS.md` records what landed and
   does not grow new checkboxes.
3. When a stable architecture rule changes, update both `docs/platform/architecture/*` and the relevant ADR/platform file.
4. If a spec is still exploratory, mark that status in the spec and link it from `docs/INDEX.md`.
5. Keep active source-of-truth documentation in English. Archive files may keep their original language until deleted or
   rewritten.
6. If documentation and code disagree, verify against code and update the stale document.

## Prepared Stable Docs

- repository entry point: [`../README.md`](../README.md);
- docs entry point: [`README.md`](./README.md);
- getting started guide;
- current project state overview;
- architecture summary;
- flow runtime reference;
- builder config schema reference.
- generated Doctum API docs via `composer run docs:build`.

## Next Gaps

1. Local infrastructure details: PostgreSQL, Redis, Horizon, and the Docker setup.
2. Boot lifecycle and registries: `DomainServiceProvider`, node handler registry, channel registry, and runtime hooks.
3. Domain authoring guide for adding code under `app/Domains/*`.
4. Testing strategy: PHPUnit feature/unit tests, tenant-aware tests, frontend tests, and `composer run test:arch`.
5. Stable summaries for Conversation Logging, Broadcasting, RAG, and Contact Segments after those specs become
   product-complete.

## Useful Principle

New documents should be added around concrete developer questions:

- "how do I run the project";
- "where should this code live";
- "how does runtime work";
- "which constraints must not be violated".
