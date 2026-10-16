---
id: convention-package-worktrees
type: convention
status: active
domains: []
paths: []
stages:
  - implement
  - consolidate
summary: Package repositories under packages/ get worktrees only in packages/<pkg>.worktrees, and the shipping agent removes them after the package PR merges; tag checkouts are removed in the same session
reviewed_at: 2026-10-16
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
