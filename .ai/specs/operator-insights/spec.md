# Operator insights

Depth: easy — the data is already written (`analytics_events`, broadcast recipients, flow logs);
what is missing is the operator's view of it.

## Idea

Collected on 2026-10-05 while reconciling the old documentation with the code (owner's request: move
all old documentation into Jig and come out with a complete roadmap). The Broadcasting milestone,
the logging and retention notes and the Inbox milestone named read-side work that was never planned:
a broadcast report, an analytics view, a live inbox.

## Goal and problem

- Who is worse off without this, and how: a staff user sends a broadcast and cannot see who received
  it or who failed; `analytics_events` collects flow starts and completions nobody can read; an
  operator in the inbox refreshes the page to see a new message.
- What is true when the work is done: a broadcast has a report page with recipients and outcomes;
  flow analytics are visible in the panel; the inbox updates live.

## Stress test

- Hidden assumptions — "this holds only if …": a live inbox needs a broadcasting backend
  (Reverb, Pusher) — there is no `config/broadcasting.php` today, so this is a new runtime
  dependency for self-hosted installs.
- The main trade-off: live updates (a new service to run) against polling (no new service, slower).
- The weakest point: read receipts depend on delivery status ingestion (spec `data-lifecycle`).
- Failure modes — cause, what breaks, the signal that shows it: an analytics view that aggregates on
  read over `analytics_events` slows the panel on a large tenant; the signal is a slow dashboard.
- Other shapes considered, and why this one: exporting analytics to an external BI tool — kept out,
  self-hosted installs should not need one.

## Scope and non-goals

- In scope: broadcast report (page, recipients drill-down, dashboard widget); flow analytics view
  over `analytics_events`; node usage in the panel (today the `flow:node-usage` command only); live
  inbox; read receipts.
- Not doing: retention of the analytics data (spec `data-lifecycle`).

## Decisions

- None yet.

## Open questions

- Live inbox through a WebSocket backend or polling — decides whether self-hosted installs get a new
  service.

## Assumptions left untested

- That `analytics_events` holds enough to answer operators' questions without a rollup table —
  untested; the first view will show it.
