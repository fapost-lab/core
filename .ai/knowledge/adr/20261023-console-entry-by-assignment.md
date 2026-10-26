---
id: adr-20261023-console-entry-by-assignment
type: adr
status: accepted
date: 2026-10-23
domains:
  - assistant
  - conversation
paths:
  - app/Domains/Assistant/Policies/AssistantPolicy.php
  - app/Http/Middleware/ResolveCurrentAssistant.php
  - app/Domains/Staff/Services/AccessibleAssistants.php
  - app/Http/Shell/NavigationBuilder.php
  - bootstrap/app.php
summary: "Owner decision: console entry is by assistant assignment (operate); each screen authorizes its own permission; view stays for management, dashboard and Solution routes."
---
# Entering an assistant's console takes an assignment to it (or the admin role), and each screen inside authorizes its own permission

## Context

The assistant-scoped console (`console` route group) resolved the assistant in the URL through
`ResolveCurrentAssistant`, which asked `AssistantPolicy::view`: `ManageAssistants` plus an assignment.
Operator handoff (task `operator-handoff`) needs operators who answer conversations without managing the
assistant. With entry tied to `ManageAssistants`, such a role could not open the inbox at all, and any
permission a role granted for one assistant's screens (the Analyst's flow sessions and contacts, for
instance) was dead in the console. Every console action already authorizes its own ability, and the
assistant-owned records check `hasAssistantAccess` in their policies.

## Decision

- `AssistantPolicy::operate` = admin, or assigned through `user_assistants`. The `console` group passes it
  to `ResolveCurrentAssistant` (`ResolveCurrentAssistant::class . ':assistant,operate'`); a user without it
  gets 404 as before.
- Each console screen authorizes its own permission; `ConversationPolicy::view` / `reply` also check access
  to the thread's assistant.
- `view` (`ManageAssistants` and access) stays the ability for managing the assistant and for its dashboard,
  and for the Solution routes (`solution.assistant`), whose applications may declare no ability of their own.
- The console offers only what the user can open: `NavigationBuilder::entryRoute()` lands the user on the
  first menu entry their permissions open (the dashboard for who may `view`); an assistant with no openable
  screen is not offered; a Solution application, context action or per-assistant shell element is offered
  only where the user can `view` the assistant.

Owner's decision at the architecture review of `operator-handoff`, 2026-10-23.

## Alternatives

- **Keep `ManageAssistants` for entry and widen it only for conversation permissions** (`operate` = access
  AND (`ManageAssistants` OR `ViewConversations`)). Rejected: it special-cases one area and keeps every other
  per-assistant permission unreachable for roles without `ManageAssistants`.
- **Give operators `ManageAssistants`.** Rejected: it hands them assistant management.

## Consequences

- Assigned staff now reach every console screen their role's permissions allow (for example the system
  Analyst role: flow sessions, flow logs, contacts) — a widening the changelog states.
- A new console screen must authorize its own permission and, for assistant-owned records, access to the
  assistant in its policy; the middleware no longer implies a management permission.
- A Solution route group that wants operators in must move to `operate` explicitly and declare an ability.
