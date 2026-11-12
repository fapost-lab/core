---
id: adr-20261111-flow-templates-expanded-at-publish
type: adr
status: accepted
date: 2026-11-11
domains:
  - flow
  - flow-builder
paths:
  - "app/Domains/Flow/Templates/**"
  - app/Domains/Flow/Handlers/TemplateNodeHandler.php
  - app/Domains/Flow/Handlers/TemplateOutputNodeHandler.php
summary: Why flow templates are copied into their host flows at publish time (UUIDv5 ids, origin, two pass-through nodes), and republished hosts from their active definitions instead of a runtime reference or a lighter subflow
reviewed_at: 2026-11-12
---
# Flow templates are expanded into their host flows at publish time; the runtime knows only two pass-through nodes

## Context

The same piece of a dialogue (call an operator, a survey, "rate the answer") repeats across flows. Copy-pasting
nodes lets an author rearrange or delete the copy, and an edit does not reach the other places. A subflow
(ADR-11) reuses a flow, but as a separate child session: the parent waits in `paused_subflow`, the child starts
with an empty `flow.*`, its exits are fixed to `success`/`cancelled`/`failed`, and a mandatory timeout kills a
child that waits for a person. The owner asked for a separate template entity placed as one node, without those
side effects (task `flow-templates`, design approved at the gate on 2026-11-11).

Two facts constrained the copy: state keys are split on `.` (`FlowSessionPersister`, markers such as
`system.set_tag.<node>`), so a copied node id cannot contain a dot; and button ids are both edge handles and
`uuid` columns of `persistent_inline_buttons`, so two copies of one template must not share them.

## Decision

1. **A template is a flow draft with `kind = template`** (`FlowKind`), edited in the builder like a flow, owned
   by an assistant, counted under the `flows` limit. `kind` is set at creation and never changes; its definitions
   repeat it. A template has no trigger (refused on draft save) and is never started:
   `FlowDefinitionRepositoryInterface::findLatestActiveByFlowId()` returns only `runnable()` (`kind = flow`)
   definitions, and every start path goes through it; flow pickers list `kind = flow` only.
2. **Expansion at publish** (`App\Domains\Flow\Templates\TemplateExpander`, called by `PublishFlowService`
   after stripping annotations and before `loop_end` denormalization and validation). Each `template` node R is
   replaced by a copy of the template's *active* definition: every id in it (nodes, edges, config values under an
   `id` key) is re-issued as `UUIDv5(fixed namespace, R + "/" + old id)`, and every string value equal to an old id
   is rewritten (edges, button handles, `loop_end.loop_node_id`). Copied nodes carry `origin`
   `{ref, template_flow_id, template_version, node_id}`. R stays in the definition, gets an `enter` edge to the
   copy's entry node, and its draft edges `R --name--> X` become `output' --default--> X`; R's config records
   `template_definition_id`, `template_version` and `exits` (name → target) so the published graph can be
   collapsed back to its draft shape.
3. **The runtime knows no templates.** The copy runs in the host's own session. Only two handlers exist, both
   pass-throughs with no state and no side effect: `template@1` (returns `enter`) and `template_output@1`
   (returns `default`). They stay registered for good, like every handler version a definition stores.
4. **Outputs are `template_output` nodes** with a name (`^[a-z][a-z0-9_]{0,63}$`, not `enter`); several nodes
   with one name are one exit; an output has no outgoing edge inside the template. `end` inside a template still
   ends the session. A template has exactly one entry node and no `template` node (no nesting in phase 1).
5. **Publishing a template republishes its hosts** in the same transaction, in `flow_id` order, each from its
   *active definition* (collapse, then expand again), never from its draft, so unpublished work is never
   published. Each host draft row is locked before its active definition is read. If any host would fail
   validation, the template publish is refused with every such flow (`template_breaks_flow`). Republished
   versions record `republished_by_template_flow_id` / `_version`. Running sessions keep the definition they
   started with.
6. **Where a template is placed** is the reverse index `flow_template_usages` (host flow, host definition,
   template), replaced per host flow on each publish and forgotten when the host draft is deleted; deletion of a
   template is refused while the index or any host draft references it. It is kept apart from
   `flow_callgraph_edges`, which drives subflow cycle and depth checks.
7. **Variables are shared** with the host: the copy reads and writes the host's `flow.*`/`contact.*`; publishing
   catches type conflicts only.

## Alternatives

- **A reference resolved at runtime** (a frame stack in the session, `current_node_id` as a path, the template
  loaded on the fly). It changes the engine loop, the persister, logs, the inspector, persistent buttons and every
  marker keyed by node id, and a session would either run a mix of versions or pin the template version at start
  anyway. Rejected: costly and risky for the same product.
- **A lighter subflow** (optional timeout, named exits, parameters). Keeps the child session, `paused_subflow`,
  special cases in the engine and router, the empty `flow.*` and the orphaned-parent problem. Subflow stays for
  its own case, an isolated flow-as-function (spec `flow-engine-v1x`, Phase 3).
- **Separate tables** for templates and their versions: duplicates drafts, publishing, versions, the editor and
  translations for one difference (a template cannot be started). Moving later is a copy of the `kind = template`
  rows; published hosts hold copies and are unaffected.
- **Manual propagation** ("a new template version is available", the author republishes). Safer for regulated
  flows, but contradicts "an edit applies everywhere". Switching to it means removing the propagation step.
- **Joined ids** (`ref.inner`, `ref::inner`): break state keys and grow with nesting; UUIDv5 has a fixed length,
  no dots, fits the `uuid` columns, and is the same id in every template version.

## Consequences

- The engine, routing, the session lock, the persister and subflows are unchanged; templates cost a publish-time
  transformation and two registry entries.
- A host's version numbers grow without its author publishing; the version records which template caused it.
- One transaction republishes every host: fine for tens of hosts, hundreds would need a queue.
- Accepted risk (review finding F1): a host that places a template for the first time while that template is
  being published can keep the old copy until the next publish of either; closing it needs one lock order
  (template drafts before host drafts) in the host publish.
  Amended 2026-11-12 (flow-templates-followups): the risk is closed by that lock order. A flow publish takes shared
  locks on every template draft of its assistant, in flow id order, before `FOR UPDATE` on its own draft; a template
  publish locks its own draft and then its hosts'. The locks are taken only on the direct publish of a flow, never
  inside the host republish, which already holds a template and would deadlock on a second one. Cost: a flow publish
  waits for a running publish of any template of its assistant (seconds), and a template draft autosave waits for
  running flow publishes of that assistant (milliseconds).
- Code that maps published node ids to the draft sees copies by `origin`, not by the draft's ids; the session
  and log screens label a copied node "template X vN → node" through `TemplateNodeOrigins`.
- Per-placement parameters (texts and settings set on the reference node) are phase 2 (task
  `flow-template-params`); nested templates and a tenant-wide library are later phases.
- Rollback: published hosts hold copies, so dropping templates breaks no running session.
