# 06. Flow validation

How a flow definition is checked before it can run. Validation is split across three layers with
different failure shapes; do not assume a rule from one layer holds in another. Wiring `FlowDefinitionValidator`
into the pipeline is tracked by Jig task `wire-flow-definition-validator`.

| Layer | Class | Failure shape | Wired into |
|---|---|---|---|
| Structural graph check | `App\Domains\Flow\Validation\FlowDefinitionValidator` | throws `FlowValidationException` on the first problem | registered as a singleton in `FlowServiceProvider`; **not called by the save / validate / publish pipeline** (tests only) |
| Builder validation | `App\Domains\Flow\Services\ValidateFlowService::execute()` | returns `FlowValidationResultDto` with a list of `FlowValidationErrorDto` (`path`, `code`, `message`) | `POST .../validate` (`BuilderFlowController::validate`) and `PublishFlowService` |
| Publish-time checks | `App\Domains\Flow\Services\PublishFlowService::execute()` | throws `FlowValidationException` carrying the DTO errors | `POST .../publish` |

## When validation runs

- **Save draft** (`BuilderFlowController::saveDraft` -> `SaveDraftService`) deliberately runs **no**
  flow validation. Drafts may be empty or half-edited; the only failures are an optimistic-lock
  conflict (409), an unparseable trigger payload (422), and request validation (422: `draft_version` and
  `definition` are required). There are no "warnings" on save.
- **Validate** is an explicit author action. It runs `ValidateFlowService` on the posted definition
  and trigger and returns every error at once.
- **Publish** runs `ValidateFlowService` again on the stored draft inside the publish transaction
  (after stripping builder-only annotation nodes and denormalising loop iterator names). Any error
  aborts the publish with `FlowValidationException`.

Edges in a stored definition use `from` / `to` / `handle`. `ValidateFlowService` and the engine's
`FlowGraphResolver` use that shape.

## `FlowDefinitionValidator` (throws)

Checks, in order, on `nodes` and `edges` given as `id/type/version` nodes and
`source_node_id/target_node_id/transition` edges:

1. At least one node.
2. Each node has a string `id`, a string `type` and an integer `version`.
3. The `type@version` pair is registered in the handler registry.
4. No duplicate node ids.
5. Edges reference existing source and target nodes; no duplicate `transition` on one source.
6. Exactly one entry node: the node with no incoming edge. The entry node is derived, never stored.
7. No orphan nodes (everything reachable from the entry node).
8. `required_transitions` (an optional per-node list) all have an outgoing edge.
9. `send_message` with `keyboard_mode = reply`, or with inline buttons / dynamic buttons, cannot have
   a connected `default` output.
10. Variable contract for `input`, `send_message`, `assign` and `branch` nodes: the new shape
    (`variable`, `save_to_variable`, `operations`, `rules`) cannot coexist with the legacy shape
    (`save_to`, `target`/`key`); a variable must construct a valid `Variable`; `assign.operations`
    must address unique `(storage, group, name)` triples; branch `left.ref` is `user_variable` or
    `source` with an allowed source (`contact`, `rag`, `call`, `system`, `flow`, `module.*`).

On success it returns `entry_node_id`, `adjacency` and `reverse_adjacency`.

## `ValidateFlowService` (DTO errors)

Annotation nodes (`comment`) are exempt from node-level checks. Per node:

| Code | Rule |
|---|---|
| `invalid_node`, `invalid_node_id` | node payload is an object with a non-empty id |
| `invalid_handler_reference`, `unknown_node_type` | `type` / integer `version` are valid and `type@version` is registered |
| `invalid_config`, `missing_config_field` | `config` is an object; every field in the handler's `configSchema()['required']` is present |
| `unknown_output_target` | an entry in `outputs` points at an existing node |
| `branch_rules_missing` | `branch` (or legacy `condition`) defines at least one rule |
| `invalid_module_reference`, `unknown_module_accessor` | `module.*` references resolve to a registered data accessor |
| `legacy_media_field`, `media_file_not_found` | `send_message` media uses `media_file_id` pointing at an existing media file |
| `emit_event_missing_type`, `emit_event_template_in_type`, `emit_event_invalid_type` | `event_type` is a literal made of alphanumerics, underscores and dots |
| `end_invalid_status` | `end.status` is a valid `EndStatus` |
| `rag_missing_knowledge_base_id`, `rag_template_in_knowledge_base_id`, `rag_missing_provider`, `rag_missing_query` | `rag_query` has a literal knowledge base id, a provider and a query template. The knowledge base is **not** looked up |
| `input_missing_save_target` | `input` has a `variable.name` (or legacy `save_to`) |
| `subflow_missing_flow_id`, `subflow_template_in_flow_id`, `subflow_invalid_timeout` | `subflow.flow_id` is a literal; `timeout` is an ISO 8601 duration |
| `loop_invalid_iterator_name`, `loop_reserved_iterator_name`, `loop_count_exceeds_budget` | iterator name is an identifier and not one of `id, language, meta, tenant_id, external_id`; a literal count above 100 is rejected |
| `loop_end_missing_loop_node_id`, `loop_end_invalid_loop_node_id` | `loop_end.loop_node_id` references an existing `loop` node |

Graph-level checks (need `edges`):

| Code | Rule |
|---|---|
| `end_node_has_outgoing_edge` | an `end` node is terminal |
| `loop_nested_not_allowed`, `loop_missing_loop_end` | no nested loops; every loop reaches its `loop_end` |
| `keyboard_node_must_be_terminal` | a `send_message` with `content_type = text_with_keyboard` has no `default` successor and is the last root in its sequence |
| `orphaned_button_edge` | a non-default edge from `send_message` matches an existing button id |
| `button_value_type_mismatch` | a button value matches the declared `save_to` variable type |
| call-graph codes (`CallGraphViolation::$code`) | subflow cycles and depth over the limit, via `CallGraphValidator`; skipped when no flow id is known |

Trigger checks (when a trigger payload is sent): `invalid_trigger_type`, `invalid_trigger_active_flag`,
`invalid_trigger_priority`, `invalid_trigger_config` (delegated to `FlowTriggerConfigValidator`),
`duplicate_exact_trigger_keyword`, `missing_event_trigger_selection`, `unknown_event_trigger_selection`.

There is **no** requirement that a flow contains an `end` node, no check that a knowledge base
exists, and no entry-node or orphan check in this layer.

## Publish-time checks (`PublishFlowService`)

After `ValidateFlowService` passes, still inside the transaction:

- `subflow_cross_assistant`: a subflow may only call a flow owned by the same assistant (looked up
  through `flow_drafts`; a missing draft is not an error here).
- `variable_type_conflict` / `variable_properties_conflict`: a typed variable already declared by a
  *different* flow in `tenant_variable_schema` cannot be redeclared with another type (or, for arrays,
  another `item_type`). Re-publishing a flow's own variables may change their types.

Then the call-graph index, the tenant variable schema and the tenant event registry are updated.

## Runtime guards that are not validation

- A session needs exactly one derived entry node; `FlowGraphResolver::resolveEntryNode()` throws
  `InvalidFlowGraphException::missingEntryNode()` otherwise.
- Reserved contact keys, group depth and structural path conflicts are enforced at write time by
  `ContactWriter`, not at save time.
