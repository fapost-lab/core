# Roadmap — Data lifecycle

Destination: every growing table has an enforced retention, deleting a contact, assistant or channel
removes what it should, and transcripts are read through a port a second store can implement.

## Phase 1 — Tables stop growing without bound

Goal: an install can run for a year without manual cleanup. Done when: scheduled commands prune
conversation messages, session history and old flow versions, and next month's message partition
exists before the first write of the month.

- [ ] Conversation retention: `retention_days` read and enforced by a scheduled prune
- [ ] Message partitions pre-created ahead of writes
- [ ] `flow_session_history` retention
- [ ] Keep the last N published flow versions

## Phase 2 — Deletion means deletion

Goal: removing a contact, assistant or channel removes its transcript. Done when: after deleting a
contact, no message, media file or history row of theirs remains, as the erasure decision defines.

- [ ] Erasure of a contact's, assistant's and channel's messages and media (after: the erasure open question — its scope is a legal call)
- [ ] PII redaction hook before a message is persisted
- [ ] Delivery status ingestion for outbound messages

## Phase 3 — A second transcript store

Goal: transcripts can move off PostgreSQL without touching the panels. Done when: the inbox reads
through `ConversationReaderInterface` and a second store passes the same tests.

- [ ] `conversation-reader-interface` — transcript read port (after: the second-store decision — the owner tied the port to the store change)
- [ ] fog: ClickHouse (or another) transcript store — no store has been chosen

## Waves

1. Conversation retention: `retention_days` read and enforced by a scheduled prune; Message partitions pre-created ahead of writes; `flow_session_history` retention; Keep the last N published flow versions
2. Erasure of a contact's, assistant's and channel's messages and media; PII redaction hook before a message is persisted; Delivery status ingestion for outbound messages
3. `conversation-reader-interface`

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
