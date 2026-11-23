---
id: adr-20261123-flow-audience-message-through-broadcasting
type: adr
status: accepted
date: 2026-11-23
domains:
  - flow
  - broadcasting
paths:
  - app/Domains/Broadcasting/Services/AutomationBroadcasts.php
  - app/Infrastructure/Flow/BroadcastingAudienceBroadcaster.php
  - app/Domains/Flow/Contracts/AudienceBroadcasterInterface.php
  - app/Domains/Flow/Handlers/SendMessageNodeHandler.php
  - app/Domains/Flow/Services/AudienceMessage.php
summary: Why send_message to tags or a segment starts a broadcast of the flow's assistant through Broadcasting's automation entry (no revision, no audit), once per node execution (origin_key), one live per node, with the run's chain depth + 1, and publishing it needs ManageBroadcast
reviewed_at: 2026-11-23
---
# A flow's message to tags or a segment is a broadcast started through Broadcasting's automation entry

## Context

`send_message@1` named one contact through its `addressee`. The `flow-automations` spec wants a flow to reach many
people at once — contacts with given tags, or a segment — and rejects a second mass-sending mechanism next to
Broadcasting. The console's way in (`BroadcastService::create()`/`send()`) asks for a confirmed revision, records
every step in the audit trail and is bound to the current assistant; a flow node has none of these, and Audit may not
be called from the flow runtime. A broadcast's completion is a Core event (`core.broadcast.completed`) raised from a
queue job, where no run is on, so the event chain depth guard counted every such event as depth 1: a flow that
broadcasts when a broadcast completes would broadcast for ever.

## Decision

- The audience is new kinds of the same field: `addressee {kind: "tags", tags}` and `{kind: "segment", segment_id}`,
  static values chosen in the builder (no expressions). The node stays `send_message@1`.
- The node starts a broadcast through `App\Domains\Flow\Contracts\AudienceBroadcasterInterface`, implemented in
  `app/Infrastructure/Flow/BroadcastingAudienceBroadcaster` over `Broadcasting\Services\AutomationBroadcasts`: no
  revision, no audit, no current assistant. What a person confirmed is the published flow; publishing a draft that adds
  such a node or changes its audience, text or name needs `ManageBroadcast` (`FlowPublishChanges`, `FlowDraftPolicy`),
  compared on the draft after template expansion, since a template can bring such a node and a host's parameter value
  rewrites its text. The right covers the node's own fields, not what its `{{flow.*}}` placeholders read upstream.
- The broadcast belongs to the flow's assistant (`flow_drafts.assistant_id`), not the run's, which an automation run
  may not have.
- The text is text only. Every language of it is rendered at the node and frozen into `broadcasts.message`;
  Broadcasting picks each recipient's language. `{{contact.*}}`/`{{module.*}}` in it are refused in every flow, since in
  a conversation they would send the run's contact's data to the whole audience.
- Once per node execution: `broadcasts.origin_key` = sha256 of the engine's `idempotencyKey:nodeId`, unique. A retried
  step gets its broadcast back (`replayed`); a new pass of a loop is a new request.
- At most one draft or running broadcast per flow node: a partial unique index on `(flow_id, flow_node_id)`; the node
  takes `error`/`broadcast_running` instead of piling runs onto the same audience from a schedule or a loop.
- `broadcasts.chain_depth` = the run's chain depth + 1; `BroadcastCompleted` carries it and `PublishCoreEvents`
  publishes the completion one level below it on `EventChainContext`, so the chain limit (5) stops such loops. Console
  broadcasts keep 0. This amends `adr-20261119-core-events-start-contactless-runs`, where the session row was the only
  carrier of a chain depth across a job boundary: the broadcast row is now a second one.
- The node does not wait for the broadcast. Refusals (`audience_missing`, `message_empty`, `broadcast_running`) and
  failures (`broadcast_failure`) take `error`; an empty audience still creates a broadcast that completes with 0.
- The rows carry `origin` (`console`|`automation`), `flow_id`, `flow_session_id`, `flow_node_id` without foreign keys:
  a flow or a run may be deleted, the broadcast stays. The console marks them "From automation" with the flow's name.

## Alternatives

- A separate `broadcast@1` node — a second node for the same act, and another shape for the builder.
- `BroadcastService::send()` from the node — it audits and needs a revision and the current assistant.
- A `system.*` marker as the once-only guard — it would stop every later pass of a loop.
- The run's assistant — an automation run may have none.
- Audience expressions — a mistake reaches everyone, and validation could not check them.
- A minimum interval per node — a setting nobody would tune; one live broadcast per node bounds it without one.

## Consequences

- Starting a broadcast from a flow leaves no audit record; the trace is the run's node trace and the broadcast row, and
  the publish that made it possible is audited.
- A broadcast stuck in `running` blocks its node until someone cancels it on the console.
- `recipient_count` at the node is an estimate; the run fixes its audience when it starts.
- Starting a flow per contact of an audience ("input towards many") is not part of this; it waits for the
  `broadcast-flows` spec. `notify` in `contacts` mode stays a second mass path until its own follow-up.
