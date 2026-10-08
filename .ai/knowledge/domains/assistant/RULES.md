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
  - app/Http/Middleware/ResolveCurrentAssistant.php
  - app/Filament/Support/SetCurrentAssistantFromPanelTenant.php
reviewed_at: 2026-10-08
---
# Assistant rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **Panel access requires the `ManageAssistants` permission and either the admin role or an
  assignment;** a user without access gets 404. Enforced: `AssistantPolicy`, `AssistantPanelTest`.
- **Deactivating an assistant deactivates its channels in one transaction;** deleting an
  assistant deletes its channels. Enforced: `AssistantService`, `AssistantServiceTest`.
- **Anything that belongs to one assistant is authorized by a permission and access to that
  assistant;** access is `User::hasAssistantAccess()` (admin, or assigned through
  `user_assistants`), checked inside the record's policy, never left to the panel's tenant alone.
  A permission alone never reaches another assistant's records. Enforced: `ChannelPolicy`,
  `BroadcastPolicy`, `FlowDraftPolicy` and their tests.

## Rules

- **Read the current assistant through `CurrentAssistantInterface`,** not
  `Filament::getTenant()`. Why: the interface also works in jobs, where Filament has no tenant,
  and it survives the move off Filament. Enforced for `App\Domains\Assistant`, `Flow`, `Webhook`,
  `App\Jobs` and `App\Infrastructure` by `tests/Architecture/CurrentAssistantSourceTest.php`;
  panel code under `app/Filament` still reads Filament's tenant until it is migrated.
- **The current assistant is only ever set explicitly:** by the console route middleware
  (`ResolveCurrentAssistant`, 404 when the assistant is missing or not viewable), by the assistant
  panel's tenant middleware (`SetCurrentAssistantFromPanelTenant`, persistent so Livewire updates
  replay it), or by a job from its payload. `CurrentAssistant` itself reads nothing ambient.
  Enforced: `CurrentAssistantTest`, `ResolveCurrentAssistantTest`, `AssistantPanelTest`.
- **Change `is_active` through `AssistantService`,** never by updating the model. Why: only the
  service cascades to channels. Only the admin edit form bypasses it today
  (`EditAssistant.php` → `AssistantService::update`, which writes `is_active` without the channel
  cascade); `AssistantSettings` has no assistant `is_active` field.
  *(proposed)*
- **Never capture `CurrentAssistantInterface` in a singleton.** Source: `conventions/worker-safety.md`. Every
  service that holds it today is `scoped`, and node handlers (with `CachedContentTranslator`) are
  built per scope (ADR-0001). Enforced for the sequence of jobs by `CurrentAssistantIsolationTest`;
  the binding of a new holder is review only.
