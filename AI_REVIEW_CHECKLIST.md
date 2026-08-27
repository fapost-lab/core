# AI REVIEW CHECKLIST

## Boundary

- bounded context unchanged?
- no hidden dependency?

## Lifecycle

- boot lifecycle preserved?
- no side effects in register?

## Tenant

- tenant context mandatory?
- scoped preserved?

## Determinism

- retry safe?
- same input same result?

## Extension

- future provider possible?
- no hardcoded first implementation?

## State

- explicit transition?
- no hidden mutation?

## Abstraction

- abstraction justified?
- real second scenario exists?

## Final Question

Will this still be understandable after 6 months?
