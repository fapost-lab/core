# Layers and Domains

## Target Structure

Planned application structure:

```text
app/
  Domains/
    Tenancy/
    Flow/
    Messaging/
    Contact/
    Bot/
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

## Still Needs to Be Detailed

- repository-layer rules;
- format for application services / use cases;
- boundary between domain and infrastructure;
- naming conventions for events, DTOs, and value objects.
