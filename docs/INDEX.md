# FAPost Core — Documentation Index

Documentation is organized by audience so platform development, extension developer docs, low-level reference, and
historical material do not compete for the same entry point.

## Start Here

- [README.md](./README.md) — short documentation entry point.
- [platform/README.md](./platform/README.md) — documentation for developing FAPost Core itself.
- [developers/index.html](./developers/index.html) — HTML developer portal for Features, Solutions, Plugins, nodes, and
  builder extensions.
- [reference/README.md](./reference/README.md) — low-level specs, schemas, diagrams, and generated API docs.
- [archive/README.md](./archive/README.md) — historical documents and old Notion exports.
- [../drafts/CURRENT_TASK.md](../drafts/CURRENT_TASK.md) — current operational focus for agents.

## Platform Development

Documents for people changing the Core platform:

- [platform/PROJECT.md](./platform/PROJECT.md) — product and architecture context.
- [platform/current-state.md](./platform/current-state.md) — what exists in code today.
- [platform/ROADMAP.md](./platform/ROADMAP.md) — platform roadmap.
- [platform/TASKS.md](./platform/TASKS.md) — only implementation checkbox tracker.
- [platform/getting-started.md](./platform/getting-started.md) — local setup and commands.
- [platform/architecture](./platform/architecture) — architecture summaries, ADRs, and platform sections.
- [platform/runtime/flow](./platform/runtime/flow) — Flow Engine runtime chain.
- [platform/plans](./platform/plans) — implementation plans, brownfield audits, and synthesis docs.

## Extension Developers

HTML docs for future implementation work on top of the platform:

- [developers/extension-model.html](./developers/extension-model.html) — Feature vs Solution vs Plugin.
- [developers/features.html](./developers/features.html) — built-in platform features.
- [developers/solutions.html](./developers/solutions.html) — composer-based Solutions.
- [developers/plugins.html](./developers/plugins.html) — runtime Plugins.
- [developers/package-development.html](./developers/package-development.html) — developing shared packages locally and
  adding a new Solution/Plugin package.
- [developers/flow-nodes.html](./developers/flow-nodes.html) — adding or extending Flow nodes.
- [developers/builder-extensions.html](./developers/builder-extensions.html) — Builder UI and frontend extension boundary.
- [developers/testing.html](./developers/testing.html) — required checks for extension work.
- [developers/api-reference.html](./developers/api-reference.html) — generated PHP API docs through Doctum.

## Reference

Detailed documents usually reached from platform or developer guides:

- [reference/specs](./reference/specs) — auth, builder, Flow Engine, and messaging specs.
- [reference/specs/flow-engine/nodes](./reference/specs/flow-engine/nodes) — per-node specs.
- [reference/builder-config-schema-reference.md](./reference/builder-config-schema-reference.md) — Builder renderer schema
  reference.
- [reference/diagrams](./reference/diagrams) — Mermaid diagrams.
- `composer run docs:build` — generates Doctum API docs to `public/core`.

## Language Policy

- Active documentation that remains as source of truth must be written in English.
- Historical archive files may keep their original language until they are deleted or rewritten.
- New developer-facing docs must be HTML under `developers/`.
- Markdown remains appropriate for platform, architecture, operations, and reference docs.

## Rules

- `platform/TASKS.md` is the only checkbox/status tracker.
- `platform/ROADMAP.md` is the milestone roadmap.
- `platform/current-state.md` describes code reality, not desired architecture.
- `developers/` is a static HTML developer portal; if a pattern is not supported yet, say so there.
- `reference/` contains detailed contracts and specs; do not use it as the main onboarding path.
- `CLAUDE.md` contains agent rules and stable code/architecture constraints; it is not roadmap documentation.
- `drafts/` must contain only `CURRENT_TASK.md`.
