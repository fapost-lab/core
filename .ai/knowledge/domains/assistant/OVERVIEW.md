---
id: domain-assistant
type: domain
status: active
summary: Assistant aggregate, lifecycle cascade to channels, staff assignment, scoped current assistant
domains:
  - assistant
topics: []
load: domain
paths:
  - "app/Domains/Assistant/**"
  - app/Providers/AssistantServiceProvider.php
  - "database/migrations/tenant/*assistant*"
  - "tests/Unit/Domains/Assistant/**"
  - "tests/Feature/Assistants/**"
  - tests/Feature/AssistantPanelTest.php
reviewed_at: 2026-10-15
---
# Assistant

## Responsibility

An assistant is the business unit a tenant configures: a name, an active flag, a default flow,
localised fallback and busy messages, commands, and available countries. This domain owns the
assistant's lifecycle — deactivation cascades to its channels — the assignment of staff users to
assistants, and the scoped **current assistant** context that the assistant's console screens, the
flow runtime and jobs read.

## Boundaries

- `app/Providers/AssistantServiceProvider.php` also wires Channels services
  (`ChannelServiceInterface`, `ChannelWebhookRegistryInterface`); the channel service tests sit
  under `tests/Unit/Domains/Assistant`.
- Current assistant: `CurrentAssistant` is `scoped` and holds only what was set explicitly: the
  console's `ResolveCurrentAssistant` middleware sets it from the URL, jobs from their payload
  (`IncomingMessageJob`, `StartFlowFromEventJob`), and a `TenantSwitcher` restore hook clears it.
- The assistant's console screens (`/assistant/{assistant}/*`) resolve the assistant from the URL;
  access goes through `AssistantPolicy` (`ResolveCurrentAssistant` answers 404 when it is not viewable).
- ADR-02 describes a `?assistant=` query parameter and a `ResolveAssistantMiddleware`. The code
  uses a path segment instead; that middleware was never registered and has been removed.
- Consumers: Flow (`Assistant` model, `CurrentAssistantInterface`), Channels, Broadcasting,
  Contact, Staff, Webhook, `app/Infrastructure/Flow/CachedContentTranslator.php`.

## Entry points

- `Services/AssistantService.php`, `Services/CurrentAssistant.php`, `Policies/AssistantPolicy.php`.
- An assistant's console: `routes/inertia.php` (`assistant/{assistant}/*`, `console.*` names),
  `app/Http/Controllers/Console/`, the shell's menu and switcher in `app/Http/Shell/`.
- Admin UI: `app/Http/Controllers/Admin/AssistantController.php` (with `AssistantChannelController`),
  `resources/js/pages/Console/Assistants/`.
