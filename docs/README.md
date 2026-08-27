# Documentation

Published documentation is at **[docs.fapost.in](https://docs.fapost.in)** and is the single source of truth.
Its source is [`site/`](./site) in this directory.

Everything else here is working material — drafts, plans, specs under design, and records. It is not
published and does not override the site. See [INDEX.md](./INDEX.md) for the full map.

- [site](./site) — the published documentation. Edit here, preview with `cd docs/site && mint dev`.
- [platform](./platform) — status, roadmap, plans, and architecture decision records.
- [reference](./reference) — specs and diagrams for implementers.
- [archive](./archive) — historical material, not current.

## Source of truth

| Question | Answer |
|---|---|
| How does something work, and how do I use it? | [docs.fapost.in](https://docs.fapost.in) |
| What is implemented so far? | [`platform/current-state.md`](./platform/current-state.md) |
| What is planned, and in what order? | [`platform/ROADMAP.md`](./platform/ROADMAP.md) |
| What is the checklist? | [`platform/TASKS.md`](./platform/TASKS.md) |
| What are we doing right now? | [`../drafts/CURRENT_TASK.md`](../drafts/CURRENT_TASK.md) |

`platform/TASKS.md` is the only status tracker. If a status looks wrong, verify against the code first, then
update `platform/current-state.md` and `platform/TASKS.md`.

If a subject is covered on the site, do not restate it here — link to it.
