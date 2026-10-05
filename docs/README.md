# Documentation

Published documentation is at **[docs.fapost.in](https://docs.fapost.in)** and is the single source of truth.
Its source is [`site/`](./site) in this directory.

Everything else here is working material — the roadmap, records, and design documents linked into
agent knowledge. It is not published and does not override the site. See [INDEX.md](./INDEX.md) for the
full map.

- [site](./site) — the published documentation. Edit here, preview with `cd docs/site && mint dev`.
- [roadmap.md](./roadmap.md) — product steps and the engineering backlog, in order.
- [platform](./platform) — records of what was built, architecture decision records, domain notes.
- [reference](./reference) — specs and diagrams for implementers.
- [archive](./archive) — historical material, not current.

## Source of truth

| Question | Answer |
|---|---|
| How does something work, and how do I use it? | [docs.fapost.in](https://docs.fapost.in) |
| What is implemented so far? | [`platform/current-state.md`](./platform/current-state.md) |
| What is planned, and in what order? | [`roadmap.md`](./roadmap.md) for the steps and the engineering backlog, [`../.ai/specs`](../.ai/specs) for each one's own plan |
| What was already built? | [`platform/ROADMAP.md`](./platform/ROADMAP.md) and [`platform/TASKS.md`](./platform/TASKS.md) |
| What are we doing right now? | `.ai/scripts/jig status` — the active Jig tasks |

Open work is planned in `.ai/specs/` and tracked as Jig tasks. `platform/ROADMAP.md` and
`platform/TASKS.md` are records: if something there looks wrong, verify against the code and fix the
record, and put new work into a spec or a task instead.

If a subject is covered on the site, do not restate it here — link to it.
