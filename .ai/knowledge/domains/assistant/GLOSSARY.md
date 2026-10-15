---
id: glossary-assistant
type: glossary
status: active
summary: Assistant, assistant console (an assistant's screens, the assistant taken from the URL), current assistant, assignment
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
# Assistant glossary

## Assistant

The configurable conversational unit of a tenant, stored in the tenant schema. Informal
synonyms: bot (avoid — a bot is a channel on a messenger).

## Assistant console

An assistant's screens in the Inertia console at `/assistant/{assistant}/*` (the "Console"
application of the shell). The assistant comes from the URL through `ResolveCurrentAssistant`; it is
an assistant, not a platform tenant.

## Current assistant

The scoped assistant context for a request or job, read through `CurrentAssistantInterface`.

## Assignment

A row in `user_assistants` that gives a staff user access to an assistant.

## Country catalog

The ISO country list (from libphonenumber) that an assistant's available countries are chosen
from.
