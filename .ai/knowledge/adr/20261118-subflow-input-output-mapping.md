---
id: adr-20261118-subflow-input-output-mapping
type: adr
status: accepted
date: 2026-11-18
domains:
  - flow
paths:
  - "app/Domains/Flow/Subflow/SubflowMapping*.php"
  - app/Domains/Flow/Handlers/SubflowNodeHandler.php
summary: Subflow input_mapping/output_mapping are optional maps of bare session variable names on subflow@1; input is evaluated in the parent before the child starts, outputs reach only the parent's flow.* and only on success, in the resume save
---
# A subflow passes values through key-value maps of bare session variable names, and hands back only on success

This fills the V1.1 parameters ADR-11 (`adr-11-subflow-composition`) reserved, and narrows its sketch.

## Context

A child session started by a `subflow` node began with an empty state, so parent and child could only
coordinate through `contact.*`. ADR-11 reserved two optional config fields for V1.1, sketched as maps from a
target path to a source path (`"flow.calculated_price": "result.price"`), and promised that a V1 flow without
them keeps working. The node is `subflow@1`; a stored node pins its version, so the change had to be one an old
node cannot notice. The builder is schema-driven and already has a key-value field. The hand-back runs in the
resumer and the engine, outside any node handler, so it has no `ContactWriter`.

## Decision

1. **The node stays `subflow@1`.** Both fields are optional and absent means nothing crosses, which is the
   versioning rule's "new optional field with a default".
2. **Both fields are objects of bare session variable names.** `input_mapping`: child variable name →
   expression over the parent's state (a template, or a literal). `output_mapping`: parent variable name →
   child variable name. A name is checked as a session `Variable` (identifier, not reserved), never a path:
   the `flow.` prefix and dotted sources of the ADR-11 sketch are refused at validate and publish
   (`SubflowMappingValidator`, exposed by the handler through `NodeConfigValidatorInterface`).
3. **Outputs reach only the parent's `flow.*`.** Contact data is already shared by parent and child, and the
   hand-back has no `ContactWriter`.
4. **Input is evaluated by the handler, in the parent's context, before the child exists.** A value that is
   exactly one placeholder is read through the state reader and keeps its type; anything else is rendered as
   text.
5. **Output is applied only on the `success` handle** (an `end` with `success`, or a child running off its
   graph). The resumer computes it from the parent's own definition snapshot and the child's final state (a
   missing child variable gives `null`, as `call`'s `result_mapping`), and `FlowEngine::resumeAfterSubflow()`
   writes it in the same optimistic-lock save that advances the parent.

## Alternatives

- **Paths as in the ADR-11 sketch** (`flow.x`, `contact.y`, `result.price`) — rejected: a contact target needs a
  writer the hand-back does not have, and a path language invites the leaf-versus-group conflicts the runtime
  only catches late.
- **A list of `{from, to: Variable}` rows like `call`'s `result_mapping`** — rejected: the sketch already named a
  map, and the key-value field renders it without a builder override.
- **A separate save of the outputs before the resume** — rejected: a crash between the two saves leaves a
  paused parent carrying success outputs, which the timeout sweeper then routes to `failed`.
- **Outputs through `FlowSessionPersister` as `stateChanges`** — not done here: the resume save is not a node
  result, and the persister has no entry for it. This is the one place session state is written outside the
  persister (review finding F3); writes stay limited to `flow.*`, which no policy restricts. Routing them
  through a persister method is the follow-up if the persister gains a rule `flow.*` writes must obey.

## Consequences

- A stored v1 node that already carried these reserved keys starts applying them; the builder never wrote them.
- An invalid input name in a definition published before validation existed fails the parent at runtime
  (`InvalidNodeConfigException`) before any child starts; an invalid output entry is skipped.
- Pinning the child's version (the next roadmap item) is independent of this and adds no field here.
