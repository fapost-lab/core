---
id: glossary-flow-builder
type: glossary
status: active
summary: Draft, draft_version, config_schema, override, vendor component, field renderer, handle
domains:
  - flow-builder
topics: []
load: domain
paths:
  - "resources/js/builder/**"
  - resources/css/builder.css
  - resources/views/builder.blade.php
  - routes/builder.php
  - "app/Http/Controllers/Builder/**"
  - app/Domains/Flow/Services/SaveDraftService.php
  - app/Domains/Flow/Services/PublishFlowService.php
  - app/Domains/Flow/Services/ValidateFlowService.php
  - app/Domains/Flow/Services/LoadBuilderFlowService.php
  - "app/Domains/Flow/Validation/**"
---
# Flow builder glossary

## Draft

The editable copy of a flow (`FlowDraft`), saved by autosave and never validated on save.

## draft_version

The draft's optimistic-lock counter. A save with a stale value is rejected with HTTP 409.

## Publish

Validate the draft and create a new flow definition version from it.

## Node type payload

What `GET /builder/node-types` returns per handler: `type`, `version`, `label`, `category`,
`config_schema`, `palette`.

## config_schema

A handler's form description, produced by `configSchema()`.

## Schema renderer

`SchemaConfigRenderer`: the generic Vue renderer for a `config_schema`.

## Override

A core Vue config panel that replaces the schema renderer for one node type; listed in the
override map in `ConfigPanel.vue`.

## Vendor component

A Solution's `*Config.vue` or `*Preview.vue`, picked up by the Vite glob.

## Field renderer

The Vue component for one schema field type, chosen from `FIELD_COMPONENTS`.

## Section

A UI grouping of field keys the schema already declares.

## Handle

An outgoing slot of a node in the editor; the same thing the runtime calls a `sourceHandle`.

## Palette

The node types a user can insert. `loop_end` is hidden from it.

## Heal

The builder store's automatic repair of loop and end constructs after an edit.
