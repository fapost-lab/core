---
id: adr-20261125-notify-contacts-retired-through-broadcasting
type: adr
status: proposed
date: 2026-11-25
domains:
  - flow
  - broadcasting
paths:
  - app/Domains/Flow/Handlers/NotifyNodeHandler.php
  - app/Domains/Flow/Services/FlowPublishChanges.php
  - app/Domains/Broadcasting/Services/AutomationBroadcasts.php
  - app/Domains/Flow/Enums/AudienceKind.php
summary: Why notify's contacts mode can no longer be added or changed at publish (notify_contacts_retired), and why a published one sends as an automation broadcast of the node's own assistant (tags or everyone), once per node execution, one live per node, always on default with the outcome in the trace
---
# A notify node's contacts mode is retired and sends what is already published through Broadcasting's automation entry

## Context

`notify@1` had two modes. Its `contacts` mode sent a message to an assistant's contacts by tag or to all of them
through its own fan-out (`SendContactNotificationJob` → `BroadcastSendJob`): no `broadcasts` row, nothing on the
console to see or cancel, no backpressure, no `origin_key`, and `{{contact.*}}` in its text rendered the run's contact
for the whole audience. `adr-20261123-flow-audience-message-through-broadcasting` made `send_message` to tags or a
segment the flow's way to start a broadcast and left this mode "a second mass path until its own follow-up". Published
flows use it, and their definitions cannot be rewritten (a write into live data).

## Decision

- **Authoring is retired.** Publishing a flow's or a template's draft that adds a `notify` node in `contacts` mode, or
  changes one against the published version, is refused with `notify_contacts_retired`
  (`FlowPublishChanges::changedContactNotifications()`: mode, assistant, audience, tags, message, compared after template
  expansion by node id). An unchanged one publishes, with a validation warning `notify_contacts_deprecated`. A template
  republishing its hosts is not checked, so a host's unchanged legacy node never stops a template's publish. The builder
  offers the mode only to a node opened in it, marked retired.
- **A published node sends through Broadcasting.** It calls `AudienceBroadcasterInterface::start()`, so the send is a
  broadcast created by `AutomationBroadcasts` (`origin = automation`, the flow and node ids, `origin_key` = sha256 of
  `idempotencyKey:nodeId`, one live broadcast per node, `chain_depth` = the run's + 1). `SendContactNotificationJob` is no
  longer dispatched; it stays deprecated for one release, so jobs queued at the deploy still run.
- **The broadcast is the node's own `assistant_id`'s**, not the flow's assistant as for `send_message`: taking the flow's
  would silently change the audience of a published node.
- **"Everyone" is an audience only for this node.** `AudienceKind::All` maps to `BroadcastTarget::All`, and
  `AutomationBroadcasts` accepts it. `send_message` cannot name it: `AddresseeResolver` has no such kind. The limit is
  structural, not a flag in the request.
- **The `notify@1` contract does not change.** Every outcome leaves through `default`: a refusal (`broadcast_running`,
  `audience_missing`, `message_empty`) or a failure (`broadcast_failure`) is named in the trace metadata
  (`broadcast_outcome`) and logged as a warning. A text with `{{contact.*}}`/`{{module.*}}` sends nothing
  (`contact_expression_refused`). The `system.contacts_notified.{node}` marker is written in every case, so a session in
  flight at the deploy that already passed the node does not send again.

## Alternatives

- Keep the mode as a second way to start a broadcast — it would need everything `send_message` has (the right to
  broadcast, the placeholder ban, segments, an `error` exit, so `notify@2`): a second node for the same act, which the
  earlier ADR rejected.
- Stop sending: published flows would go silent without anyone being told.
- Route through Broadcasting without the one-live-per-node limit (another key): one console row per conversation, and
  the spam protection lost.
- The flow's assistant — changes the audience of a node already published.
- Take `error` on a refusal — a contract change (`notify@2`), and a published node has no edge for it.
- Migrate published nodes to `send_message` automatically — a write into live definitions and drafts.

## Consequences

- Behaviour changes for published nodes: while the node's broadcast runs, another pass (in a conversational flow,
  another contact reaching the node) sends nothing (`broadcast_running`); a message without base-language text sends
  nothing (`message_empty`); a text reading a contact sends nothing; sending goes through Broadcasting's backpressure and
  its `outbound_messages` limit, which cancels the broadcast instead of dropping single messages.
- No schema change; `git revert` restores the old path, and the broadcasts made meanwhile stay valid rows.
- Removing `SendContactNotificationJob`, `BroadcastSendJob` (if nothing else uses it) and Contact's use of
  `ContactNotifyTarget` — the Contact↔Flow import cycle through them — is a follow-up a release later.
- This closes the last consequence of `adr-20261123-flow-audience-message-through-broadcasting`.
