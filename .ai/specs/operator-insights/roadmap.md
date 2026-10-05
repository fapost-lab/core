# Roadmap — Operator insights

Destination: a staff user sees the outcome of a broadcast, the activity of flows and new inbox
messages without leaving the panel or reloading it.

## Phase 1 — Reports from data already collected

Goal: what the platform records is visible. Done when: a broadcast has a report page listing
recipients with their outcome, and the panel shows flow starts and completions per flow.

- [ ] Broadcast report: page, recipients drill-down, dashboard widget
- [ ] Flow analytics view over `analytics_events`
- [ ] Node usage in the panel

## Phase 2 — A live inbox

Goal: an operator sees a new message as it arrives. Done when: a contact's message appears in an
open conversation without a reload.

- [ ] Live inbox updates (after: the WebSocket-or-polling open question — it decides the runtime dependency)
- [ ] Read receipts (after: spec `data-lifecycle` delivery status ingestion — receipts are that data)

## Waves

1. Broadcast report: page, recipients drill-down, dashboard widget; Flow analytics view over `analytics_events`
2. Node usage in the panel; Live inbox updates
3. Read receipts

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
- A wave entry names an item by its title (the text before its first ` — `) or its task id,
  entries separated by `;` — `jig spec plan` reports an entry that names no item or several.
-->
