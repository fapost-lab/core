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
  - app/Providers/Filament/AssistantPanelProvider.php
  - "app/Filament/Assistant/Pages/**"
  - "app/Filament/Resources/Assistants/**"
  - "database/migrations/tenant/*assistant*"
  - "tests/Unit/Domains/Assistant/**"
  - "tests/Feature/Assistants/**"
  - tests/Feature/AssistantPanelTest.php
---
# Assistant

## Responsibility

An assistant is the business unit a tenant configures: a name, an active flag, a default flow,
localised fallback and busy messages, commands, and available countries. This domain owns the
assistant's lifecycle — deactivation cascades to its channels — the assignment of staff users to
assistants, and the scoped **current assistant** context that the assistant panel, the flow
runtime and jobs read.

## Boundaries

- `app/Providers/AssistantServiceProvider.php` also wires Channels services
  (`ChannelServiceInterface`, `ChannelWebhookRegistryInterface`); the channel service tests sit
  under `tests/Unit/Domains/Assistant`.
- Current assistant: `CurrentAssistant` is `scoped`. `get()` prefers `Filament::getTenant()`,
  then an explicit override. Jobs set the override (`IncomingMessageJob`,
  `StartFlowFromEventJob`), and a `TenantSwitcher` restore hook clears it.
- The assistant panel (`/assistant/{id}`) uses Filament's native tenancy with `Assistant` as the
  tenant model; access goes through `User::canAccessTenant()` and `AssistantPolicy`.
- ADR-02 describes a `?assistant=` query parameter and `ResolveAssistantMiddleware`. The code
  uses a path segment instead, and that middleware is not registered.
- Consumers: Flow (`Assistant` model, `CurrentAssistantInterface`), Channels, Broadcasting,
  Contact, Staff, Webhook, `app/Infrastructure/Flow/CachedContentTranslator.php`.

## Entry points

- `Services/AssistantService.php`, `Services/CurrentAssistant.php`, `Policies/AssistantPolicy.php`.
- `app/Providers/Filament/AssistantPanelProvider.php`, `app/Filament/Assistant/`.
- Admin UI: `app/Filament/Resources/Assistants`.
