# Platform Development

This section is for developers changing FaPost Core itself.

## Read First

- [current-state.md](./current-state.md) — what exists in the repository today.
- [PROJECT.md](./PROJECT.md) — product, architecture, layers, and domain context.
- [ROADMAP.md](./ROADMAP.md) — milestone roadmap.
- [TASKS.md](./TASKS.md) — implementation checklist and partial/done status.
- [getting-started.md](https://docs.fapost.in/contributing/local-setup) — local setup and commands.

## Architecture And Runtime

- [architecture/adr](./architecture/adr) — architecture decision records, including superseded ones.
- [runtime/flow](./runtime/flow) — webhook → queue → worker → session → flow engine → outbound message.

Architecture summaries now live on the site: [Runtime architecture](https://docs.fapost.in/contributing/runtime)
and [Repository layout](https://docs.fapost.in/contributing/repo-layout).
- [plans](./plans) — implementation plans, audits, and synthesis documents.

## Rules

- Keep implementation status in [TASKS.md](./TASKS.md), not scattered across specs.
- Keep code reality in [current-state.md](./current-state.md).
- Move stable low-level contracts to [../reference](../reference) when they are useful outside platform development.
