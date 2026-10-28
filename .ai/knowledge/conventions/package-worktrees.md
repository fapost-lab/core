---
id: convention-package-worktrees
type: convention
status: active
domains: []
paths:
  - tools/dev-link-packages.php
  - tools/worktree-setup
  - tools/dev-link-npm.mjs
stages:
  - implement
  - consolidate
summary: Package repositories under packages/ get worktrees only in packages/<pkg>.worktrees and are removed after their PR merges; composer dev:link links a checkout only when Core requires it at a version its constraint accepts
reviewed_at: 2026-10-28
---
# Package worktrees

## Practice

The repositories under `packages/` (`fapost-foundation`, `fapost-support`, `fapost-ui`,
`fapost-saas`, Solution and connector packages) are separate git repositories. `jig task start
--worktree` and the app's worktree cleanup see only Core's own worktrees, so a worktree made
inside a package is invisible to them and is nobody's to remove unless its author removes it.

- A worktree for a package lives in `packages/<package>.worktrees/<name>`, named after the
  Core task it serves, and is created only when the package's main checkout is busy with
  another branch.
- The agent that ships a task removes every package worktree the task created, once the
  package pull request is merged: `git worktree remove`, delete the local branch, then
  `git worktree prune`. A task is not consolidated while one of its package worktrees remains.
- A checkout of a tag (to read an old release, diff versions or write release notes) is
  removed in the same session that made it. Prefer `git show <tag>:<path>` or
  `git diff <tag>..<ref>`, which need no worktree at all.
- Remove the empty `packages/<package>.worktrees/` directory with its last worktree: an
  empty one makes `git worktree list` run inside it fall through to Core's repository.

### What `composer dev:link` links

`tools/dev-link-packages.php` links a checkout from `packages/` only when the root manifest
(`composer.json`, or the file `COMPOSER` names — `composer.local.json` under the overlay)
requires the package and the checkout's latest tag (`git describe --tags --abbrev=0`)
satisfies the constraint; `@dev`, `dev-*` and path repositories always pass. Anything else
keeps Composer's locked copy. A link it made earlier for a package whose version is now
refused is removed, with a request to run `composer install`; a link to a package only the
overlay requires stays while Composer lists it as installed, so a plain `composer dev:link`
does not break the overlay. `tools/dev-link-npm.mjs` applies the same rule to `node_modules/`:
the checkout's `package.json` `version` must satisfy the range Core's `package.json` declares.

### What a Core task worktree runs on

`packages/` is not shared with every Core task worktree. `worktree.setup` runs
`make worktree-setup` (`tools/worktree-setup`) in each new worktree, and it decides by whether
`packages/` is there:

- without it (the default), the links into `packages/` carried over in `vendor/` and
  `node_modules/` are removed and the dependencies installed from the lock files, so the task
  runs on the versions Core pins;
- a task that edits a package is started with `jig task start <id> --worktree --share packages`
  (or `jig task bootstrap <id> --share packages` later): the setup copies
  `composer.overlay.json` from the main checkout, installs the overlay without Composer's
  scripts, and links the checkouts Core accepts.

The Makefile derives `WORKDIR` from the container's mounts, so `make` in a worktree runs in that
worktree inside devilbox rather than in the main checkout.

## Example

A task changed `fapost-foundation` on `feat/media-link-policy` in
`packages/fapost-foundation.worktrees/media-link-policy`. After the foundation pull request
merged, the shipping agent runs, from `packages/fapost-foundation`:

```
git worktree remove ../fapost-foundation.worktrees/media-link-policy
git branch -D feat/media-link-policy
git worktree prune
```

## Rationale

Without this, finished worktrees piled up: by 2026-10-16 `fapost-foundation` held eight
worktrees of merged branches and five detached checkouts of release tags, none of them known
to any task or cleanup tool. Each is a full checkout an agent may later mistake for live
work, and `composer dev:link` only ever links the main checkout, so they serve nothing.

The linker used to link every checkout in `packages/` unconditionally. With PR #296 sharing
`packages/` into task worktrees, a `fapost/foundation` checkout at v0.33.0 — breaking since
0.31 — was linked under a `main` that requires `^0.30`, and no Core test could run. A checkout
is linked only when Core accepts its version.

Sharing `packages/` with every task (2026-10-26, `worktree.share`) still left a task either on
those checkouts or, without them, on dangling links — never on the lock that CI runs. Jig 0.36
added `worktree.setup` and `--share` for one task, so the default became the lock and the
local checkouts became a choice of the task that edits them.
