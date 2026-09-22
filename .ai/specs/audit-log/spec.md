# Audit Log / Staff Activity

Depth: easy — the write/no-write boundary, the storage location (tenant schema) and the retention
policy are already decided in the source; what remains open is interface-level (UI shape, diff
scope, visibility), not the shape of the feature itself.

## Idea

A journal of staff actions: who changed what, and when, in the tenant panel.

## Goal and problem

- Who is worse off without this, and how: tenants running several staff members in one panel —
  nobody can tell who disabled a channel, who edited a published flow, or who exported contacts.
  This is both a trust problem for the tenant and an incident-response gap: when a tenant reports
  "the bot stopped responding," the first question — did anything change, and who changed it — has
  no answer today.
- What is true when the work is done: create/update/delete on `Assistant`, `Channel`, `Flow`
  (including publishing a version), `Contact` and segments, `Broadcast`, `User`/`Role`, and tenant
  settings changes are all recorded, along with staff sign-in/sign-out, with configurable retention
  and a scheduled cleanup command so the table does not grow without bound.

## Stress test

- Hidden assumptions — "this holds only if …": this holds only if the audited surface stays
  confined to panel/configuration changes and does not try to also absorb flow-session runtime
  events, message delivery, or webhook traffic — those already have their own logs.
- The main trade-off: completeness of the trail (storing full old/new value diffs) against the risk
  of leaking channel secrets or other sensitive configuration into the log.
- The weakest point: the line between "worth logging here" and "already logged elsewhere" is not
  fully drawn — in particular, whether platform-administrator actions on a tenant belong in this
  journal is still open.
- Failure modes — cause, what breaks, the signal that shows it: retention is configurable but not
  self-enforcing — if the scheduled cleanup command is never wired up and scheduled, the table grows
  without limit; the signal is table growth outpacing tenant activity.
- Other shapes considered, and why this one: folding this into Conversation Logging (M3) was
  implicitly rejected — that logs the dialogue with a contact, this logs staff actions over
  configuration; they are kept as separate layers because mixing them was explicitly called out as
  a mistake to avoid.

## Scope and non-goals

- In scope: create/update/delete on `Assistant`, `Channel`, `Flow` (including version publish),
  `Contact` and segments, `Broadcast`, `User`/`Role`, and tenant settings changes; staff
  sign-in/sign-out.
- Not doing: flow-session runtime events, message delivery, webhook traffic — each already has its
  own journal. Also not doing this as a revenue push right now: its only stated justification is
  selling to tenants with multiple staff, and earning money is explicitly not this stage's goal
  (`docs/roadmap.md`, Out of scope).

## Decisions

- Storage lives in the tenant schema, not landlord — rejected: landlord-side storage, because the
  journal belongs to the tenant and must be deleted along with it.
- Retention is configurable, enforced by a scheduled cleanup command — rejected: unlimited
  retention, because the table would otherwise grow without bound.
- Kept as a layer separate from Conversation Logging (M3) — rejected: recording staff actions in the
  same mechanism as contact-dialogue logging, because they are different kinds of record (dialogue
  vs. configuration change) and the source explicitly warns against mixing them.
- Not scheduled ahead of the release track — rejected: pulling it forward, because it does not block
  release, only sales to multi-staff tenants, and it is deliberately sequenced after the release
  track lands.

## Open questions

- Does this need a dedicated Filament resource with a list and filters, or a "History" tab on each
  record? — decides the UI shape and how much dedicated screen real estate the feature gets.
- Do we store full old/new value diffs, and how do we keep channel secrets out of the log? — decides
  what gets written per entry and what redaction has to happen before persisting.
- Who can see the journal: only the tenant owner, or any role with a dedicated permission? — decides
  the authorization model behind the log's read side.
- Do platform-administrator actions on a tenant appear in this journal? — decides whether the
  journal is staff-only or also covers platform-level intervention.

## Assumptions left untested

- That `spatie/laravel-activitylog` (already a dependency at v5, currently unused by any model) is
  the right mechanism to build this on — taken at idea depth; would be tested by checking it against
  the tenant-schema storage and retention requirements during implementation.
- That "several staff in one panel" is really what blocks a sale, as the source states — taken at
  idea depth, sourced from the roadmap's own stated reasoning; would be tested against actual sales
  conversations once revenue becomes a goal for this stage.
