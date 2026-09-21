---
id: domain-flow-builder
type: domain
status: active
summary: "Visual flow editor: drafts, autosave, validate and publish, schema-driven node config UI"
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
  - "app/Filament/Assistant/Resources/Flows/**"
  - "app/Filament/Assistant/Resources/FlowGroups/**"
  - app/Http/Requests/SaveDraftRequest.php
  - app/Http/Requests/ValidateFlowRequest.php
  - app/Http/Requests/CallTestRequest.php
---
# Flow builder

## Responsibility

The authoring side of flows: the Inertia + Vue visual editor, draft autosave, validation and
publishing. A draft is freely editable and is not validated; publishing validates it and creates
a new immutable flow definition inside a transaction. Runtime execution is domain `flow`.

The Telegram mini app (`resources/js/tma`) is a separate surface and not part of this domain.

## Boundaries

- Server side is Flow domain services: `SaveDraftService`, `ValidateFlowService`,
  `PublishFlowService`, `LoadBuilderFlowService`, and `Validation/`. The builder reaches runtime
  tables only through publishing.
- Node forms come from each handler's `configSchema()`, built with the support package's
  `Fapost\Support\Builder\Schema\Schema`. `NodeTypesController` walks the handler registry and
  serves them at `GET /builder/node-types`.
- Solution components are discovered at build time by the Vite glob
  `vendor/fapost/*/resources/js/builder/*{Config,Preview}.vue`
  (`resources/js/builder/utils/vendorComponents.ts`, ADR-06); adding one needs a frontend rebuild.

## Entry points

- Routes: `routes/builder.php` (middleware `auth`, `tenant`, `verified`); controllers in
  `app/Http/Controllers/Builder/`.
- Frontend: `resources/js/builder/app.ts`, the single page `pages/FlowBuilder/FlowEditor.vue`,
  stores in `store/` (`builderStore`, `registryStore`, `selectionStore`, …), API client
  `api/builderApi.ts`, autosave `useAutoSave`.
- Config UI: `components/editor/ConfigPanel.vue` (the override map) and `SchemaFields.vue`
  (`FIELD_COMPONENTS`).
