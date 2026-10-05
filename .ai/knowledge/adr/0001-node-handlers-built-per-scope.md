---
id: adr-0001-node-handlers-built-per-scope
type: adr
status: accepted
date: 2026-09-11
domains:
  - flow
paths:
  - "app/Domains/Flow/Registry/**"
  - app/Domains/Flow/Contracts/NodeHandlerFactoryInterface.php
  - app/Infrastructure/Flow/ContainerNodeHandlerFactory.php
summary: Why the node handler registry stores classes and builds handlers per resolve in the current scope instead of holding instances or resolver closures
reviewed_at: 2026-10-05
---
# ADR-0001: The node handler registry keeps classes and builds each handler in the current scope

## Context

`NodeHandlerRegistry` is a singleton that lives as long as the Horizon worker, and the
registry used to hold handler **instances**. Everything a handler received in its
constructor lived as long, so the handlers kept the scoped collaborators of whichever job
built the registry: the message sender, `CachedContentTranslator` (tenant context, current
assistant, `TenantSettings`), `MediaService`, `SubflowStarterService`. A later job for
another tenant used them — the cross-tenant leak that the long-lived worker rules forbid.

Constraints that shaped the answer:

- The Flow runtime, `Registry/` included, must not touch the container or facades
  (`FlowRuntimeIsolationTest`), so the registry cannot call `$container->make()` itself.
- `NodeHandlerInterface` exposes `type()` / `version()` only as instance methods, and the
  foundation contract registers an extension handler by class
  (`CoreRegistrarInterface::registerNodeHandler(string $handlerClass)`).
- `SubflowNodeHandler → SubflowStarterService → FlowEngine → NodeHandlerRegistry` is a
  construction cycle.

## Decision

- The registry maps `type@version` to a handler **class**. `register(class-string)` builds one
  throwaway instance to read and validate the handler's identity, then keeps only the class.
- `resolve()` and `all()` build a new instance on every call through the port
  `NodeHandlerFactoryInterface`. Its implementation, `ContainerNodeHandlerFactory`, lives in
  `app/Infrastructure/Flow` and resolves through the container, which always yields the
  scoped bindings of the job running at that moment. `has()` answers existence checks
  without building anything.
- Handlers take their collaborators as ordinary constructor dependencies. A `Closure` in a
  handler signature is forbidden (`FlowRuntimeIsolationTest::test_node_handlers_do_not_take_resolver_closures`).
- A `Closure` in a constructor stays legitimate only to break a construction cycle — the
  lazy `engineResolver` of `SubflowStarterService` is that case, not a lifetime workaround.

## Alternatives

- **Resolver closures (`Closure(): X`) in handler constructors.** Shipped briefly. The real type
  lives only in PHPDoc, each closure captures the container (a service locator inside the
  runtime), every handler author must know the lifetime of each dependency transitively,
  and an extension handler registered by class gets no protection at all.
- **A scoped registry.** `FlowDefinitionValidator` (a singleton) would have to become scoped,
  and freezing plus extension registration would have to replay in every scope — it ends
  up as "classes plus per-scope construction" anyway, with more moving parts.
- **Scoped ports passed through `NodeExecutionContext`.** Changes a foundation DTO, and an
  extension handler's own scoped services cannot travel that way.
- **Static `type` / `version` on the foundation contract.** Would remove the throwaway
  instance at registration, but is a breaking change to a separately released package.
  Possible later; nothing here depends on it.
- **Lazy indexing on the first `resolve()`.** Would let the subflow cycle-breaker go, but moves
  duplicate and version errors from registration to runtime.

## Consequences

- A handler is constructed on every engine step and every `resolve()`. Scoped and singleton
  dependencies come from the container's cache; `bind` ones are rebuilt. The cost is small
  next to database and HTTP work. Nothing may cache a resolved handler beyond one scope.
- Registering a handler builds its dependency graph once, inside the registry's singleton
  factory. A new handler whose graph reaches `FlowEngineInterface` or the registry eagerly
  recurses — break such a cycle lazily, as `SubflowStarterService` does.
- A test that needs a stateful handler binds it with `$app->instance(Stub::class, $stub)` and
  registers `Stub::class`; `resolve()` then returns that instance.
- When Core implements `CoreRegistrarInterface`, `registerNodeHandler()` forwards to
  `register()` unchanged. `registerDataAccessor()` and `registerRagAdapter()` also take
  classes, and their registries currently hold instances — they need the same treatment
  before extension accessors or adapters with scoped dependencies arrive.
