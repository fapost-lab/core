# Layers and Domains

## Target Structure

Current domain-oriented structure:

```text
app/
  Domains/
    Tenancy/
    Flow/
    Messaging/
    Contact/
    Assistant/
    Media/
    Staff/
    Shared/
  Jobs/
  Http/
  Console/
  Providers/
```

Inside each domain:

```text
Domains/{Domain}/
  Models/
  Contracts/
  Services/
  Jobs/
  Http/
  Exceptions/
```

## Layer Responsibilities

- `Contracts` - domain interfaces and stable integration points.
- `Services` - business logic without Laravel helper-driven code.
- `Models` - Eloquent models when the domain needs a persistence layer.
- `Jobs` - asynchronous orchestration within the domain.
- `Http` - controllers and requests, without business logic.
- `Providers` - bindings, registries, and runtime hook registration.

## Core Conventions

- Use `final class` by default.
- Use dependency injection instead of `app()` and `resolve()` in domain code.
- Do not place business logic in `Controllers` or `Jobs`.
- Do not access Eloquent directly from domain services; use a repository layer.
- Use `enum` for fixed sets instead of groups of constants.
- Do not create new base folders such as `app/Features`, `app/Solutions`, or `app/Plugins` unless an architecture
  decision explicitly introduces them.

## Detailed Sources

- [`platform/01-overview-layers.md`](./platform/01-overview-layers.md)
  - layer model.
- [`platform/04-assistant-domain.md`](./platform/04-assistant-domain.md)
  - Assistant domain.
- [`platform/05-contacts.md`](./platform/05-contacts.md) - Contact
  domain.
- [`platform/06-flow-engine.md`](./platform/06-flow-engine.md) - Flow
  domain.
- [`platform/10-message-pipeline.md`](./platform/10-message-pipeline.md)
  - Messaging pipeline.
