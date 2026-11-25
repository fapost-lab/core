---
id: adr-20261125-broadcast-publish-right-covers-what-leads-to-it
type: adr
status: proposed
date: 2026-11-25
domains:
  - flow
  - broadcasting
paths:
  - app/Domains/Flow/Services/FlowPublishChanges.php
  - app/Domains/Flow/Support/AudienceMessageCone.php
  - app/Domains/Flow/Policies/FlowDraftPolicy.php
summary: Why publishing a flow needs ManageBroadcast when what leads to its message to tags or a segment changes (the ancestor cone, triggers, engine, a template some host with a broadcast places), and what stays outside
---
# Publishing a broadcast's flow needs ManageBroadcast for what leads to the broadcast, not only for the node

## Context

A flow's `send_message` to tags or a segment starts a broadcast without a confirmed revision: publishing the flow is
the confirmation, and it needs `ManageBroadcast` (`adr-20261123-flow-audience-message-through-broadcasting`). That
right covered the node's own fields only (audience, text, broadcast name). Everything the text's `{{flow.*}}`
placeholders read is written upstream, by an `assign`, an `input`, a `call` or a placed template; the edges and loops
leading to the node and the flow's triggers decide whether and how often it runs. A publisher with `PublishFlow` and
without `ManageBroadcast` could change all of that, and with it what an approved broadcast says to its whole audience
and how often it goes out.

## Decision

- Publishing a flow's draft needs `ManageBroadcast` when, for any `send_message` to tags or a segment in it (after
  template expansion, as published), the node is new or its own fields changed (as before), or its **cone** differs from
  the cone of the same node id in the published version, or the flow has such a node and the draft changes its
  expression engine or any of its triggers, `message` included.
- The cone is the node and every node that can reach it — by an edge, or by the implicit jump a `loop_end` makes back
  to its `loop` (`config.loop_node_id`) — plus the edges into them as `(from, handle, to)`. A node is compared by
  `type`, `version`, `config` and `parameters`, maps with sorted keys; a `loop_end`'s `iterator_name` is left out
  (publishing writes it from the `loop`, which is in the cone). Position, label, edge ids and key order are not
  compared, so an unchanged republish never needs the right (`App\Domains\Flow\Support\AudienceMessageCone`).
- A `subflow` or `start_flow` node that starts a flow whose definition it runs (a pinned `subflow`'s pinned version,
  else the active one) has an audience message is treated as one: its `input_mapping` seeds the variables that flow's broadcast reads, and how often it runs is how often the
  broadcast can go out. Its cone is compared the same way, so a caller with no broadcast of its own still needs the
  right to change what it hands over.
- A template's draft is compared unexpanded with its published version. When its graph or engine changed, it needs the
  right if one of its own audience messages (or such callers) has a changed cone, or if the active definition of any
  flow that places it has one: a template publish republishes its hosts without their own check, so the cautious rule is
  per host, not per host cone.
- The refusal has its own message (`builder.publish_denied.broadcasts_upstream`), naming blocks, links, triggers and the
  expression engine, because the publisher may never have touched the broadcast node.
- Removing an audience message, and every change outside its cone, needs nothing.

This amends `adr-20261123-flow-audience-message-through-broadcasting`: its sentence "The right covers the node's own
fields, not what its `{{flow.*}}` placeholders read upstream" no longer holds.

## Alternatives

- Any change to a flow that has an audience message needs the right — simple, but a fix after the broadcast, or on a
  branch that never reaches it, would need it too, and `PublishFlow` would mean little on such flows.
- Only automatic triggers count — a `message` trigger also starts the graph that leads to the broadcast and sets how
  often it goes out.
- For a template, rebuild each host with the new version and apply the cone rule — precise, but it repeats the
  expansion of every host inside the policy; it can replace the per-host rule later.
- Follow `subflow` callees: what a called flow returns feeds the cone — but the callee is another flow with its own
  publish, and following call chains across flows in the policy costs more than the residual. The other direction —
  a caller handing a broadcasting flow its input — is covered, one level deep (the callee's own definition, not the
  flows it calls in turn).

## Consequences

- Residual, by design: what a `subflow` callee returns (its publish is checked only if it has an audience message
  itself; the `subflow` node's own config is in the cone), a broadcasting flow reached two calls away, and data from
  outside the version — a trigger's payload (an event another flow emits included), an API's answer, a contact's
  input. Turning a flow on or off is not affected, so a caller published while its callee had no broadcast, or while
  the callee was off, is not checked again when the callee gains one or is turned on.
- Custom roles with `PublishFlow` and without `ManageBroadcast` can no longer change what leads to a broadcast within
  the flow or from a flow that directly starts it; the
  system roles that publish (Admin, ContentManager) hold `ManageBroadcast`, so for them nothing changes.
- The check walks the graph once per audience node inside the publish transaction (O(nodes + edges) each), and a
  changed template reads the active definition of each host.
