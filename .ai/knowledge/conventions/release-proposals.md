---
id: convention-release-proposals
type: convention
status: active
domains: []
paths: []
stages:
  - consolidate
summary: How to compute the pending semver bump from merged PR titles pre-1.0, and that a v* tag is only ever pushed on explicit instruction
---
# Release proposals

## Practice

The version lives only in the `v*` tag; there is no version file to bump.

- After opening or merging a pull request, state its version effect (MINOR / PATCH /
  none) from its Conventional Commits title and any `BREAKING CHANGE:` footer or `!`.
- After a merge into `main`, compute the pending bump over every pull request in
  `git log <latest tag>..origin/main` (`git describe --tags --abbrev=0 origin/main`
  finds the latest tag). Take the pull request numbers from the subjects
  (`Merge pull request #N` or a squash `(#N)`) and read their titles with
  `gh pr view N` — a merge commit subject alone carries no type. Do not select by
  merge date; it misses by seconds.
- Pre-1.0: any `feat` or breaking change raises MINOR; otherwise `fix` or `perf`
  raises PATCH; `refactor`, `test`, `docs`, `chore`, `build`, `ci` alone raise
  nothing. A title outside Conventional Commits is classified by reading the pull
  request's diff instead.
- If the pending bump is not none, propose a release: the next tag, the pull
  requests that justify it, and the commands from
  https://docs.fapost.in/contributing/releases. If it is none, say nothing.
- Never create or push a tag without an explicit instruction: a pushed tag publishes
  images and is not undone.

## Example

On this branch, `git describe --tags --abbrev=0 origin/main` resolves to `v0.3.1`.
Walking `git log v0.3.1..origin/main` turns up merges like
`chore(tooling): silence shellcheck findings and skip linked packages in pint (#54)`
(squash merge, `chore` — no bump) and `Merge pull request #53` (a merge commit with
no type in its own subject — read with `gh pr view 53` to get the real title). A
`feat(...)` title anywhere in that range would make the pending bump MINOR even if
every other pull request in it were `chore`/`docs`/`test`.

## Rationale

Deriving the bump from Conventional Commits titles, not from memory or merge dates,
keeps the proposal reproducible from `git log` and `gh pr view` alone. Refusing to
create or push a tag without an explicit human instruction is a hard stop, not a
style preference: a pushed tag triggers image publication that cannot be rolled
back, unlike a commit or a branch.
