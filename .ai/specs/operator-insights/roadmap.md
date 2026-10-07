# Roadmap — Operator insights

Destination: a staff user sees the outcome of a broadcast, the activity of flows and new inbox
messages without leaving the panel or reloading it — every screen built on the kit, after
ui-foundation.

## Phase 1 — Reports from data already collected

Goal: what the platform records is visible. Done when: a broadcast has a report page listing
recipients with their outcome, and the panel shows flow starts and completions per flow.

- [ ] Broadcast report: page, recipients drill-down, dashboard widget (after: ui-foundation phase 2 — the screen is built on the kit)
- [ ] Flow analytics view over `analytics_events` (after: ui-foundation phase 3 — it replaces `FlowActivityChart` on the kit's admin dashboard)
- [ ] Node usage in the panel (after: ui-foundation phase 3 — the screen is built on the kit)

## Phase 2 — A live inbox

Goal: an operator sees a new message as it arrives. Done when: a contact's message appears in an
open conversation without a reload.

- [ ] Live inbox updates (after: ui-foundation phase 2 — builds on its live-updates composable, Echo when a broadcaster is configured and polling otherwise)
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
