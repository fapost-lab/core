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
  - "database/migrations/tenant/*assistant*"
  - "tests/Unit/Domains/Assistant/**"
  - "tests/Feature/Assistants/**"
  - tests/Feature/AssistantPanelTest.php
  - app/Http/Middleware/ResolveCurrentAssistant.php
reviewed_at: 2026-10-15
---
# Assistant rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **Entering an assistant's console requires access to that assistant, not a permission;**
  `AssistantPolicy::operate` (admin, or assigned through `user_assistants`) is the ability the
  `console` route group passes to `ResolveCurrentAssistant`, and a user without it gets 404. Every
  screen inside then authorizes its own permission. Managing the assistant and its dashboard stays
  `view` (`ManageAssistants` and access), and so do the Solution routes (`solution.assistant`), which
  may carry no ability of their own. Why: an operator holds conversation permissions only; with entry
  tied to `ManageAssistants` the permissions a role grants for one assistant's screens were dead. The
  console lands the user on the first menu screen they can open (`NavigationBuilder::entryRoute()`); a Solution
  application is offered only where the user can `view` the assistant. Owner's decision, 2026-10-23
  (`adr-20261023-console-entry-by-assignment`). Enforced: `AssistantPolicy`, `OperatorHandoffTest`,
  `ResolveCurrentAssistantTest`, `AssistantPanelTest`.
- **Deactivating an assistant deactivates its channels in one transaction;** deleting an
  assistant deletes its channels. Enforced: `AssistantService`, `AssistantServiceTest`.
- **Anything that belongs to one assistant is authorized by a permission and access to that
  assistant;** access is `User::hasAssistantAccess()` (admin, or assigned through
  `user_assistants`), checked inside the record's policy, never left to the panel's tenant alone.
  A permission alone never reaches another assistant's records. Enforced: `ChannelPolicy`,
  `BroadcastPolicy`, `FlowDraftPolicy` and their tests.

## Rules

- **Read the current assistant through `CurrentAssistantInterface`.** Why: the interface also works
  in jobs, where no request names an assistant. Core no longer has Filament's tenant to read:
  a `Filament\` or `Livewire\` reference in `App\` fails phpstan's unknown-class checks in Core's CI,
  and PHPat `NoFilamentTest` where Filament is installed (a local Core checkout with the SaaS
  package overlaid; no CI runs it there).
- **The current assistant is only ever set explicitly:** by the console route middleware
  (`ResolveCurrentAssistant`, 404 when the assistant is missing or the route group's ability — `operate` in the console, `view` for Solution routes — denies it) or by a job from
  its payload. `CurrentAssistant` itself reads nothing ambient.
  Enforced: `CurrentAssistantTest`, `ResolveCurrentAssistantTest`, `AssistantPanelTest`.
- **Change `is_active` through `AssistantService`,** never by updating the model. Why: only the
  service cascades to channels. Only the admin edit form bypasses it today (the console's
  `StaffAssistantService::update` → `AssistantService::update`, which writes `is_active` without the
  channel cascade, as the removed Filament form did);
  `AssistantSettings` has no assistant `is_active` field.
  *(proposed)*
- **A nested `runForTenant` hides the current assistant inside and returns it after.** A synchronous job in an
  HTTP request (the channel webhook sync) must not leave the request without its assistant, and the inner tenant
  never sees the outer one. At the top level of a job there is nothing to return, so it resets as before.
  Enforced: `CurrentAssistantIsolationTest`.
- **Never capture `CurrentAssistantInterface` in a singleton.** Source: `conventions/worker-safety.md`. Every
  service that holds it today is `scoped`, and node handlers (with `CachedContentTranslator`) are
  built per scope (ADR-0001). Enforced for the sequence of jobs by `CurrentAssistantIsolationTest`;
  the binding of a new holder is review only.
