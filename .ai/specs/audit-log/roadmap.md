# Roadmap — Audit Log / Staff Activity

Destination: every create/update/delete on `Assistant`, `Channel`, `Flow`, `Contact`/segments,
`Broadcast`, `User`/`Role` and tenant settings, plus staff sign-in/sign-out, is recorded in a
tenant-scoped, retention-managed audit log that authorized staff can review.

## Phase 1 — Capture staff actions

Goal: every in-scope write lands in a tenant-scoped audit log. Done when: performing any in-scope
action produces a matching log row stored in the acting tenant's schema.

- [ ] Record create/update/delete on `Assistant`, `Channel`, and `Flow` (including version publish)
      in a tenant-scoped audit log
- [ ] Record create/update/delete on `Contact`/segments, `Broadcast`, `User`/`Role`, and tenant
      settings changes in the same log (after: Assistant/Channel/Flow logging — reuses the same
      recording mechanism, only extends the covered entities)
- [ ] Record staff sign-in/sign-out events in the log
- [ ] fog: whether platform-administrator actions on a tenant are captured here — depends on the
      open question of who the journal is meant to hold accountable

## Phase 2 — Keep it bounded

Goal: the log does not grow without limit. Done when: a scheduled command prunes entries past the
configured retention period.

- [ ] Add configurable retention with a scheduled cleanup command
- [ ] fog: whether old/new value diffs are stored, and how channel secrets are kept out of them —
      not decided, blocks whether diff storage ships at all

## Phase 3 — Make it visible

Goal: staff with permission can read the journal. Done when: an authorized user can open a view of
the log — resource or tab — and see the recorded actions for their tenant.

- [ ] fog: dedicated Filament resource vs. a "History" tab on each record — shape not decided
- [ ] fog: who can see the journal (owner-only vs. permission-gated role) — blocks how the view is
      authorized

## Waves

1. Capture staff actions on `Assistant`/`Channel`/`Flow`
2. Capture staff actions on `Contact`/segments/`Broadcast`/`User`/`Role`/tenant settings, and staff
   sign-in/sign-out (both depend only on the recording mechanism built in wave 1)
3. Retention and the scheduled cleanup command
4. Visibility (Filament resource or History tab) — waits on the open UI and authorization questions

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
-->
