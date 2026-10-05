---
id: rule-assistant
type: rule
status: active
summary: Panel access policy, deactivation cascade, read current assistant via its interface
domains:
  - assistant
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Assistant/**"
  - app/Providers/AssistantServiceProvider.php
  - app/Providers/Filament/AssistantPanelProvider.php
  - "app/Filament/Assistant/Pages/**"
  - "app/Filament/Resources/Assistants/**"
  - "database/migrations/tenant/*assistant*"
  - "tests/Unit/Domains/Assistant/**"
  - "tests/Feature/Assistants/**"
  - tests/Feature/AssistantPanelTest.php
reviewed_at: 2026-10-05
---
# Assistant rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **Panel access requires the `ManageAssistants` permission and either the admin role or an
  assignment;** a user without access gets 404. Enforced: `AssistantPolicy`, `AssistantPanelTest`.
- **Deactivating an assistant deactivates its channels in one transaction;** deleting an
  assistant deletes its channels. Enforced: `AssistantService`, `AssistantServiceTest`.

## Rules

- **Read the current assistant through `CurrentAssistantInterface`,** not
  `Filament::getTenant()`. Why: the interface also works in jobs, where Filament has no tenant.
  Panel code uses both today. Review only. *(proposed)*
- **Change `is_active` through `AssistantService`,** never by updating the model. Why: only the
  service cascades to channels. Only the admin edit form bypasses it today
  (`EditAssistant.php` → `AssistantService::update`, which writes `is_active` without the channel
  cascade); `AssistantSettings` has no assistant `is_active` field.
  *(proposed)*
- **Never capture `CurrentAssistantInterface` in a singleton.** Source: `conventions/worker-safety.md`. A known
  violation exists: `CachedContentTranslator` holds it and is built inside the singleton
  `NodeHandlerRegistry`.
