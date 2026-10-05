# Data lifecycle

Depth: normal — the retention gaps are visible in the code, but two choices (what deletion of a
contact must erase, and where transcripts live long term) carry legal and operational weight.

## Idea

Collected on 2026-10-05 while reconciling the old documentation with the code (owner's request: move
all old documentation into Jig and come out with a complete roadmap). The Conversation Logging
design, ADR-10 and the flow versioning notes each promised retention and deletion that the code does
not do.

## Goal and problem

- Who is worse off without this, and how: an operator of a self-hosted install whose tables grow
  without bound, and a tenant asked to erase a contact's data who cannot — conversation messages
  live in a partitioned table without foreign keys, so deleting a contact, assistant or channel
  leaves its messages behind.
- What is true when the work is done: every growing table has a stated retention that a scheduled
  command enforces; deleting a contact, assistant or channel removes what the design says it removes;
  transcripts can be read through a port that a second store could implement.

## Stress test

- Hidden assumptions — "this holds only if …": partitions by month make retention cheap only if
  retention is a whole number of months; a per-tenant retention on a shared partitioned table means
  row deletes, not partition drops.
- The main trade-off: per-tenant retention settings (what tenants ask for) against partition drops
  (what keeps the database fast).
- The weakest point: erasure. "GDPR cascade delete" was claimed in the design and is false for
  messages; the right scope of erasure (messages, media, history, logs) is a legal question, not an
  engineering one.
- Failure modes — cause, what breaks, the signal that shows it: a prune command that drops the
  current partition loses live transcripts; the signal is a conversation with messages before a
  date and none after.
- Other shapes considered, and why this one: foreign keys on the partitioned message table —
  rejected by the Conversation design for write throughput; deletion is done by the domain instead.

## Scope and non-goals

- In scope:
  - conversations: read `conversation.retention_days` (defined, never read) and prune; pre-create
    message partitions ahead of writes (today they are created on first write only); delete a
    contact's, assistant's and channel's messages and media when the owner is deleted; a PII
    redaction hook before persistence; ingest delivery status so `UpdateConversationDeliveryStatusJob`
    has a producer;
  - flow history: retention for `flow_session_history` (ADR-10; only `flow_logs` is pruned today);
  - flow versions: keep the last N published versions (the notes said N = 50; nothing prunes today);
  - the transcript read port and a second store: `ConversationReaderInterface` (built together
    with the store change; its earlier Jig task was closed unbuilt) and a ClickHouse store.
- Not doing: analytics retention and aggregation (spec `operator-insights`).

## Decisions

- Transcripts stay in PostgreSQL until a second store is chosen — owner's decision of 2026-09-23,
  first recorded on the since-closed task `conversation-reader-interface`; the reader port is built together with the
  store change, not before.

## Open questions

- What deleting a contact must erase: messages, media, session history, flow logs — a legal call;
  blocks the erasure item.
- Retention per tenant or per install — decides row deletes against partition drops.
- Which second store, if any, and when — blocks phase 3.

## Assumptions left untested

- That pruning by partition is fast enough on the largest tenant — taken at normal depth; a prune
  run on production-sized data would test it.
