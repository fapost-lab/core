---
id: glossary-assistant
type: glossary
status: active
summary: Assistant, assistant panel (whose Filament tenant is an assistant), current assistant, assignment
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
# Assistant glossary

## Assistant

The configurable conversational unit of a tenant, stored in the tenant schema. Informal
synonyms: bot (avoid — a bot is a channel on a messenger).

## Assistant panel

The operational Filament console at `/assistant/{id}`. **Filament's "tenant" in this panel is an
assistant, not a platform tenant.**

## Current assistant

The scoped assistant context for a request or job, read through `CurrentAssistantInterface`.

## Assignment

A row in `user_assistants` that gives a staff user access to an assistant.

## Country catalog

The ISO country list (from libphonenumber) that an assistant's available countries are chosen
from.
