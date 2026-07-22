# Runtime Principles

## Tenancy

Target model:

- tenant context is mandatory for runtime;
- core must not branch into `single-tenant` vs `saas` modes;
- tenant context switching is encapsulated by the tenancy domain;
- user data lives in tenant schemas.

## Flow Engine

Core rules:

- flow is stored as a JSON graph;
- the execution loop must remain deterministic;
- node handlers are resolved by `(type, version)`;
- handler registries must be built at boot time with no database lookups on the hot path.

## Concurrency

Incoming message processing is expected to use three protection layers:

1. Redis idempotency key.
2. Distributed lock for the conversation session.
3. Optimistic locking at the `flow_sessions.version` level.

## Queues

Target queue isolation:

- `messaging.transactional`
- `messaging.broadcast`
- `flow.execution`
- `sync.external`
- `scheduled.triggers`

## Webhook Routing

Runtime model:

- URL format `/webhook/{channel}/{public_hash}`;
- `public_hash` resolves through Redis;
- landlord must not participate in routing hot paths;
- inbound messages are handed to `IncomingMessageJob` and resolved through the tenant-aware routing pipeline.

## Detailed Sources

- [`platform/08-concurrency-idempotency.md`](./platform/08-concurrency-idempotency.md)
  - concurrency and idempotency.
- [`platform/09-flow-versioning.md`](./platform/09-flow-versioning.md) -
  flow versioning.
- [`platform/10-message-pipeline.md`](./platform/10-message-pipeline.md)
  - message pipeline.
- [`../../reference/diagrams/01-webhook-pipeline.md`](../../reference/diagrams/01-webhook-pipeline.md) - webhook pipeline
  diagram.
- [`../../reference/diagrams/02-flow-engine-loop.md`](../../reference/diagrams/02-flow-engine-loop.md) - flow engine loop.
