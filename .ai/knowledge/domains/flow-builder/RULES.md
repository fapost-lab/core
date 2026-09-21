---
id: rule-flow-builder
type: rule
status: active
summary: Override lookup order, unvalidated drafts, schema-first node UI, vendor components need a rebuild
domains:
  - flow-builder
topics: []
load: domain
requires: []
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
# Flow builder rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **Config panel lookup order is: core override, then vendor component, then schema renderer**
  (`ConfigPanel.vue`).
- **A schema section references only fields the schema declares.** Enforced:
  `tests/Unit/Domains/Flow/NodeHandlerSchemaSectionsTest.php`.
- **Saving a draft never validates; validate and publish do.** A stale `draft_version` returns
  409. Why: autosave must not fail on a half-built graph. Enforced: `tests/Feature/Domains/Flow/BuilderApiTest.php`.
- **Every asset `builder.blade.php` loads is a declared Vite input.** Enforced:
  `tests/Unit/Frontend/ViteEntrypointsTest.php`.

## Rules

- **A new node's UI starts as `configSchema()` on its handler.** Add a core override only when
  the schema renderer cannot express the UI, by adding a key to the map in `ConfigPanel.vue`.
  Source: AGENTS.md. Review only.
- **A new schema field type needs a `FIELD_COMPONENTS` entry.** An unknown type silently renders
  as a text field. Review only. *(proposed)*
- **Plugins cannot ship Vue components at runtime** — only through the vendor glob and a
  rebuild. Source: AGENTS.md, ADR-06.
- **A button or select `value` is language-agnostic; only its label is translated.** Source:
  AGENTS.md. Review only.
