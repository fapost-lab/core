---
id: convention-published-docs-updates
type: convention
status: active
domains: []
paths: []
load: always
summary: When a task must update docs.fapost.in, and what counts as too small to edit
---
# Published documentation updates

## Practice

At consolidation, decide whether docs.fapost.in (`docs/site/`) must change. It must when the
task changed something a reader of the site relies on:

- a public contract or extension surface (Foundation interfaces, node contract, builder schema);
- what an operator does: a migration to run, an environment variable, a queue, a command,
  an upgrade or restart step, a deployment requirement;
- how a contributor works: commands, required services, CI checks, the PR or CLA process;
- behaviour a staff user sees in the panels or the builder.

It does not when the change is internal: a refactor, a bug fix that restores documented
behaviour, tests, `.ai/knowledge`, `docs/platform` status. Do not reword, restyle or extend
a page the task did not make wrong.

Update only the sentences the change made false or incomplete, in the same pull request,
and keep `docs.json` navigation in step (see `AGENTS.md`, Documentation Discipline). Record
the decision in `task.md` as one line: `Docs: updated <page> — <why>` or
`Docs: not needed — <why>`.

## Example

`redis-integration-suite`: the default test run now needs Redis → `contributing/testing.mdx`
updated. `fix-remap-defects`: a policy fix with no documented contract changed → not needed;
the behaviour change went into the pull request's Upgrade notes.

## Rationale

The site is the single source of truth for users, operators and contributors; a stale page
misleads all three. Edits for their own sake churn a published site and bury the ones that
matter. The rule is `load: always` because a task that touches no domain — tooling, CI,
documentation — can still change what the site must say, and a stage-only rule would miss it.
