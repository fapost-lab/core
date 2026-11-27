---
id: convention-release-proposals
type: convention
status: active
domains: []
paths:
  - .github/workflows/release-tag.yml
  - .github/workflows/publish-images.yml
  - ".github/scripts/release-tag*"
stages:
  - consolidate
summary: "How to compute the pending semver bump from merged PR titles pre-1.0, and that merging the release notes pull request tags the release: what its body must carry, and that agents never push a tag by hand"
reviewed_at: 2026-11-27
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
  requests that justify it, and the process from
  https://docs.fapost.in/contributing/releases. If it is none, say nothing.

### The release pull request is the release

Merging the release notes pull request into `main` is the instruction to release. The
**Release tag** workflow (`.github/workflows/release-tag.yml`) then waits for CI on the
merged commit, pushes the annotated tag and calls **Publish images**, which builds the
images and publishes the GitHub release. Agents never create or push a `v*` tag by
hand; a release that the workflow refused is the owner's to tag, or a new notes pull
request's.

What the release pull request must carry is owned by
https://docs.fapost.in/contributing/releases#cutting-a-release (`docs/site/contributing/releases.mdx`):
a `docs(release): ... vX.Y.Z` title, an author who is an owner, member or collaborator,
and in its **body**, outside HTML comments (which the workflow ignores), a
`Release-Version:` line, a `Release-Notes-Through:` line and the release text between
the `<!-- release-notes:start -->` and `<!-- release-notes:end -->` markers. A notes
task writes them into its pull request body and does not edit it after the merge: the
release is published only with the text the workflow validated. The squash commit on `main` is the pull
request's title and body, so the branch commit, where `jig task ship` writes the
trailer, never reaches `main`; on a release epic it does not even reach the epic's
pull request, so the body line is what the workflow finds. A release without the
markers is refused, not published with generated notes.

## Example

On this branch, `git describe --tags --abbrev=0 origin/main` resolves to `v0.3.1`.
Walking `git log v0.3.1..origin/main` turns up merges like
`chore(tooling): silence shellcheck findings and skip linked packages in pint (#54)`
(squash merge, `chore` — no bump) and `Merge pull request #53` (a merge commit with
no type in its own subject — read with `gh pr view 53` to get the real title). A
`feat(...)` title anywhere in that range would make the pending bump MINOR even if
every other pull request in it were `chore`/`docs`/`test`.

After `v0.3.1`, a notes pull request titled `docs(release): notes for v0.4.0` whose body
states the same version is tagged `v0.4.0` when it merges. The same pull request
stating `v0.4.1` in its body, or titled `v0.5.0`, is refused, and nothing is tagged.

## Rationale

Deriving the bump from Conventional Commits titles, not from memory or merge dates,
keeps the proposal reproducible from `git log` and `gh pr view` alone. A pushed tag
triggers image publication that cannot be rolled back, unlike a commit or a branch, so
the one act that releases is the merge a person (or an unattended run allowed to merge
releases) already decides on, and the workflow refuses on any doubt rather than tag.
The version is read from the body because GitHub's squash commit takes the pull
request's title and body, not the branch commit where the trailer is. The release text
needs explicit markers because it has `##` headings of its own, so no heading level can
delimit it.
