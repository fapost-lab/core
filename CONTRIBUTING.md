# Contributing to FaPost Core

The full guide lives in the documentation: start at
[Contributing](https://docs.fapost.in/contributing/overview). This page is the
short version of the rules that govern how code moves through the repository.

## Branches

- `main` is the only long-lived branch. Nothing is pushed to it directly; it is
  releasable at every commit. There is no `develop`.
- Every change starts from `main` on a short-lived branch:
  `feat/…`, `fix/…`, `chore/…`, `docs/…`, `refactor/…`.
- Keep a branch to days, not weeks. Rebase on `main` if it falls behind; split
  work that outgrows one reviewable change into several pull requests.

## Pull requests

- Before opening one:

  ```bash
  vendor/bin/pint --dirty
  composer test
  composer run test:arch
  ```

- Title and commits use Conventional Commits: `feat(flow): …`, `fix(webhook): …`,
  `chore: …`, `docs: …` — see
  [Commit messages](https://docs.fapost.in/contributing/commits). The title
  becomes the commit on `main` and a line in the release notes.
- One concern per pull request. Fill in the template: what, why, how to check.
- A change to a public contract carries its documentation change in the same
  pull request.
- A first pull request accepts the [Contributor License Agreement](./CLA.md)
  with a comment the CLA check asks for — see
  [Licence, CLA and trademark](https://docs.fapost.in/contributing/legal).
- Pull requests are squash-merged once CI is green and reviewed; the branch is
  deleted on merge.

## Coding agents

The project is developed with AI coding agents through
[Jig](https://jig.fapost.in). `AGENTS.md` holds the rules every agent reads;
`.ai/knowledge/` holds architecture, rules and per-domain knowledge, and a change
that alters a rule updates it in the same pull request. An agent is optional —
see [Coding agents and Jig](https://docs.fapost.in/contributing/coding-agents).

## Releases

- A release is an annotated `vMAJOR.MINOR.PATCH` tag on `main`. Pushing it
  builds and publishes the container images; nothing else needs bumping.
- Pre-1.0: MINOR for new behaviour or a contract change, PATCH for fixes only.
- A hotfix is a `fix/` branch to `main` and the next PATCH tag. A tag is never
  moved or deleted.

Details, including maintenance branches and pre-releases:
[Releases and versioning](https://docs.fapost.in/contributing/releases).
