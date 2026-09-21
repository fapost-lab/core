---
id: convention-data-accessor-boundary
type: convention
status: active
domains:
  - flow
paths:
  - app/Domains/Flow/Support/ModuleDataAccessorRegistry.php
  - app/Domains/Flow/Contracts/DataAccessorRegistryInterface.php
  - app/Domains/Flow/Contracts/MutableDataAccessorRegistryInterface.php
summary: Data accessors behind module.* are read-only and deterministic within one engine step
---
# Data accessor boundary

`Fapost\Foundation\Contracts\DataAccessorInterface`: the only source of `module.*` state,
registered in `ModuleDataAccessorRegistry`.

## Practice

```
MUST:     be read-only
MUST:     be deterministic within one engine step: the same key read twice returns the same value
MUST NOT: write to the session, the contact or any module's storage
```

## Example

A handler reads `module.<name>.<key>` through the scoped state reader; the reader asks the
registered accessor and never stores the answer in the session.

## Rationale

`module.*` is a read-only projection (`AGENTS.md`, Flow Engine Rules). A write through an
accessor would bypass the engine's transaction and retry, and a value that changes inside a
step makes a branch and the node after it disagree.
