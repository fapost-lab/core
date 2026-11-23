---
id: adr-20261119-expression-engine-chosen-per-flow
type: adr
status: accepted
date: 2026-11-19
domains:
  - flow
  - flow-builder
paths:
  - "app/Domains/Flow/Expression/**"
  - app/Domains/Flow/Services/PublishFlowService.php
  - app/Domains/Flow/Validation/ContactContextChecker.php
  - app/Domains/Flow/Templates/TemplateExpander.php
  - app/Domains/Flow/Services/ValidateFlowService.php
  - app/Http/Requests/Console/FlowRequest.php
  - "resources/js/pages/Console/Flows/**"
summary: Why the expression engine is chosen per flow on its draft and snapshotted explicitly at publish (republish keeps the host's snapshot), checked with that engine (expression_engine_unknown, template_engine_mismatch), instead of ADR-08's tenant setting
reviewed_at: 2026-11-23
---
# The expression engine is chosen by the author of each flow and snapshotted onto its definition at publish

## Context

ADR-08 (`docs/platform/architecture/adr/08-expression-language.md`) made expression evaluation pluggable and planned
the engine as a tenant setting (`tenant.settings.expression_engine`), validated at tenant boot and snapshotted per
definition. Only the snapshot column and the registry were built; the engine always came from the column default
`template`. Building the tenant setting (task `flow-expression-engine-per-tenant`) was turned around at the design
gate by the owner: the language belongs to a flow, not to the whole tenant. Different flows of one tenant may need
different languages (a template or a Solution's flow brings its own), and with a tenant setting a change of language
reaches every future publish of every flow at once, while two languages cannot live side by side in one tenant.

## Decision

1. **The draft holds the choice.** `flow_drafts.expression_engine` (string(32), default `template`) is the engine the
   author chose for the flow or template. It is set on the console flow form (`FlowRequest`: an id of
   `ExpressionEngineRegistry`, optional — absent keeps the engine). The select is shown only while more than one
   engine is registered, and a hidden select sends nothing. Templates keep the default for now (no edit form).
   Amended 2026-11-23 (task `builder-ui-fixes`): the select is also shown while the flow's own engine is not
   registered (point 4), else nothing could choose another; it still sends nothing until an installed engine is
   picked (`resources/js/pages/Console/Flows/expressionEngine.ts`).
2. **Publishing snapshots it explicitly.** `PublishFlowService::createDefinition()` always writes
   `flow_definitions.expression_engine`: the draft's choice on a publish, the active definition's snapshot on a
   republish by a template (`$source`), so unpublished work in a host's draft — a changed engine included — is never
   published by somebody else's template publish. Old versions and running sessions keep their snapshot.
3. **A flow is checked with its own engine.** The engine id is passed explicitly to `ValidateFlowService::execute()`
   and `TemplateExpander::expand()` / `draftErrors()` (required there): `/validate` and a publish take it from the
   draft, a republish from `$source`, a template's own publish from the template draft. It is never looked up by flow
   id inside a checker, which would give the draft's engine during a republish.
4. **An engine the registry does not know is refused**: `expression_engine_unknown` at path `expression_engine`, on
   validate and publish (nothing is written). Reachable when a Solution's or plugin's engine leaves the registry.
5. **`ContactContextChecker` reads references with the flow's engine** (`extractReferences`), lifting the known limit
   of `adr-20261118-node-contact-context-checked-at-publish`; an unknown engine reads none (the flow is refused
   anyway).
6. **A template and its host share one engine**: `TemplateExpander` refuses a reference whose template's active
   definition was published with another engine (`template_engine_mismatch` at `nodes.<ref>`; on a template publish
   it becomes `template_breaks_flow` for that host). The copy carries expressions in the template's syntax while the
   host's parameter values are in the host's, and one definition runs one engine. A subflow needs no such rule: only
   values cross its boundary (`input_mapping` is evaluated in the parent, `output_mapping` maps names).
7. **The operator decides which engines exist** through the registry (what is installed); a tenant-level allow-list
   that narrows it is deferred until a second engine exists.

Deliberately unchanged: the engine's `validate()` is not run at publish (`TemplateEngine::validate()` rejects a bare
`{{contact}}` and Solution namespaces, so it would start refusing existing flows), and `FlowEngine` still falls back to
`template` for an empty or unknown id (ADR-08 wanted a failed session; unreachable while every snapshot is a
registered engine). Both are left to the task that adds a second engine.

## Alternatives

- **A tenant setting, as ADR-08 planned** (with an operator command) — rejected at the gate: language is a property
  of a flow; a tenant switch changes every flow's next publish at once and cannot mix languages. It also needed a
  settings migration in every schema and boot validation that would make Tenancy depend on Flow.
- **A tenant allow-list now** — accepted as a direction, deferred: with one engine the list has nothing to narrow,
  and a setting, command and screen built ahead would be surface nobody uses.
- **The select in the builder** — rejected: flow settings (`logging_enabled`, `is_public`) live in the console form;
  the builder edits the graph.
- **Scanning contact references with every registered engine** — not needed once the flow's engine is known.
- **An engine from `.env`** — against `conventions/runtime-settings.md`.

## Consequences

- A second engine becomes selectable as soon as it is registered, but it does not yet work end to end: `{{…}}` is
  hard-wired outside `TemplateEngine` (`TemplateRenderer::render()` skips strings without `{{`, `TemplateResolver`,
  `SubflowNodeHandler::SINGLE_PLACEHOLDER_PATTERN`, the "is dynamic" checks of `ValidateFlowService` and
  `ButtonLimitChecker`, and the builder's variable picker, branch compiler, `CallConfig.vue`, `LoopConfig.vue`).
  The second engine's task fixes these; Solutions cannot register an engine yet (no `SolutionCoreRegistrar` hook).
- Changing a flow's engine does not rewrite its expressions: expressions in the old syntax are caught at run time by
  the new engine's rules, not at publish, until `validate()` is enabled.
- Rolling back is cheap: the draft column stays harmless and every snapshot so far is `template`.
