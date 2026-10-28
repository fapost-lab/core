---
id: convention-knowledge-recording
type: convention
status: active
domains: []
paths: []
stages:
  - consolidate
  - implement
summary: Durable project rules go to .ai/knowledge through jig knowledge, never to an agent's native memory or Boost's record-rule; personal preferences may stay in memory
---
# Recording knowledge

## Practice

A rule the next agent or teammate should inherit — a settled decision, a non-obvious trap, a
standing constraint — is written to `.ai/knowledge/` through `jig knowledge new` (and
`jig knowledge paths|stages|summary` for its frontmatter), so `jig context` resolves it for the
paths and stages it applies to.

- **Not an agent's native memory.** Memory tools (Claude Code memory, Codex notes and the like)
  are personal and local to one machine; nobody else's agent ever reads them.
- **Not Laravel Boost's `record-rule`.** It writes to `.ai/rules/`, a second place for rules
  that `jig context` does not resolve and that drifts from `.ai/knowledge/`. The existing
  `.ai/rules/architecture.md` is kept as an adopted source of `RULES.md`; new rules do not go
  there.

Native memory is the right place only for what is personal: the language one speaks in a
session, how much autonomy one gives an agent, one's own workflow habits.

## Example

The PHPat trap in `.ai/knowledge/RULES.md` (rules run only when registered in `phpstan.neon`,
and `$regex = true` silently matches nothing) was found once, recorded, and now reaches every
task that touches `tests/Architecture/**` without anyone rediscovering it.

## Rationale

The Laravel Boost guidelines block in `AGENTS.md` used to carry this rule pointed at
`record-rule`; Boost dropped it when it regenerated the block. The intent — durable rules are
shared through the repository, not kept in one person's agent — still holds, and in this project
the shared place is `.ai/knowledge/`: `AGENTS.md` keeps every rule in one place because a copy
kept in two drifts.
