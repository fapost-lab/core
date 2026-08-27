# Architecture

This section is the architecture summary for FAPost Core.

Detailed architecture documents live in this directory. Use [`../INDEX.md`](../INDEX.md) as the documentation index.
`CLAUDE.md` contains agent rules and coding constraints only; it is not the architecture index or task tracker.

## Section Contents

- [Architecture Vision](./vision.md) - the boundaries of core and its role in the overall system.
- [Layers and Domains](./layers-and-domains.md) - how the codebase should be structured.
- [Runtime Principles](./runtime.md) - tenancy, queues, flow execution, and concurrency.

## Architecture Sources

- [`adr`](./adr) - accepted architecture decisions.
- [`platform`](./platform) - platform architecture by domain/runtime area.
- [`../../reference/specs`](../../reference/specs) - implementation specs for auth, builder, flow engine, and messaging.
- [`../plans`](../plans) - implementation plans and brownfield audits.

## Status

This section mixes implemented constraints and target architecture. Before changing it, compare against
[Current Project State](../current-state.md) and [`../TASKS.md`](../TASKS.md). Do not mark a target
feature as implemented until the code exists.
