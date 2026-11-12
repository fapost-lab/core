---
id: adr-20261111-flow-template-parameters
type: adr
status: accepted
date: 2026-11-11
domains:
  - flow
  - flow-builder
paths:
  - "app/Domains/Flow/Templates/**"
  - resources/js/builder/utils/templateNodes.ts
  - resources/js/builder/components/editor/config/overrides/TemplateConfig.vue
  - resources/js/builder/components/editor/config/TemplateParameterDeclarations.vue
summary: Why a template parameter is a field of a node inside the template, exposed by a node-level declaration and replaced in the copy by the host's value after the ids are re-issued, rather than {{param.x}} placeholders
reviewed_at: 2026-11-12
---
# A template parameter is an exposed field of a node inside the template, replaced in the copy by the host's value at expansion

## Context

Phase 1 (`adr-20261111-flow-templates-expanded-at-publish`) copies a template's active definition into
each host at publish, so a template's texts and settings are the same wherever it is placed. Owners
need "looking for an operator" worded differently in different flows without copying the template
(task `flow-templates`, design §7, approved at the gate on 2026-11-11; built in `flow-template-params`).
Content texts are language maps (`{lang: text}`) resolved at runtime by `ContentTranslator::resolveField`,
and settings such as a timeout or a role are not texts at all.

## Decision

1. **The template author exposes a field of an inner node.** The declaration is a node-level key
   `parameters: [{key, label, field}]` next to `config` (`TemplateExpander::NODE_PARAMETERS`). `field` is
   a top-level config key the node type's `configSchema()` declares, of an exposable type
   (`TemplateExpander::EXPOSABLE_FIELD_TYPES`: text, number, choice, toggle, duration, list, state and
   flow pickers). Object-shaped fields (`object-array`, `object`, `key-value`, `json`) and every field of
   `template`, `template_output`, `loop` and `loop_end` nodes are refused: they carry the ids and links the
   copy re-issues. Keys match `^[a-z][a-z0-9_]{0,63}$` and are unique in the template. The template's own
   publish checks this (`template_parameter_invalid_key|duplicate_key|invalid_field`).
2. **The host sets values on the reference node**, `config.parameter_values` by key, in the shape of the
   source field (a language map for a translatable text). The reference keeps a snapshot
   `config.parameters` (`key, label, node_type, node_version, field`, in that order), refreshed by `expand`
   and by the builder on load like `outputs`; the builder renders each parameter with the source field's
   schema component. The template's current value travels only in `availableTemplates`, never in the
   snapshot, so editing a template's text never changes a host's draft.
3. **Expansion substitutes after the ids are re-issued**, so a host's text never passes through the id map;
   copies drop the `parameters` key. An empty value — absent, null, blank, an empty list, a language map with
   no non-blank text; `false` and `0` are values — keeps the template's value. A value replaces the whole
   field: a host's text replaces the template's language map with its translations, and is translated on
   the host's Content tab — except that a language the host left blank in its map keeps the template's text
   for that language (`TemplateExpander::withTemplateLanguages`), so a contact never gets an empty message
   where the template has a text.
4. **Removing or renaming a parameter follows the rule for outputs.** A non-empty value for a key the
   template no longer has is `template_unknown_parameter`; on a template publish it refuses the publish with
   `template_breaks_flow` for every such host. A leftover empty value breaks nothing. The engine,
   expressions and the two pass-through handlers are unchanged.

## Alternatives

- **`{{param.x}}` placeholders in the template's texts** — cannot express language maps or non-text
  settings (who to call, a timeout) and need changes in the expression engine. Rejected at the gate.
- **A template-level list of parameters** (a column on drafts and definitions) — a migration and a second
  save path for the same information; a declaration on the node goes away with the node and is copied with
  the definition for free.
- **Nested paths** (`buttons.0.label`) — replacing items that carry button ids would break the copy's
  handles; left out.

## Consequences

- The builder and the server keep two copies of the exposable types, the key pattern and the emptiness
  rule (`utils/templateNodes.ts`, `TemplateExpander`); the server is the authority at publish.
  Amended 2026-11-12 (flow-templates-followups): a shared fixture (`tests/Fixtures/flow-template-parameter-rules.json`)
  checked by `TemplateParameterRulesParityTest` and `templateNodes.test.ts` holds the two copies equal.
- Translatable fields are the Content tab's list (`utils/translatableFields.ts`); a parameter on another
  text field (e.g. `request_operator` texts) is a plain string for every language.
- A host value is not type-checked against the source field beyond what `ValidateFlowService` checks on
  the expanded graph; a template that keeps a key but moves it to a field of another type does not refuse
  its publish.
- The activity log never records values: publishing records only the version (the graph is a change
  marker, activity-log design D5).
