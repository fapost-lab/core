# Webhook Pipeline: an incoming message

The full path of a message from a channel (Telegram here) to the bot's reply.

```mermaid
sequenceDiagram
    actor U as User
    participant TG as Telegram API
    participant WC as Ingress<br/>(Go gateway or PHP-FPM)
    participant RD as Redis
    participant Q as Queue<br/>flow.execution
    participant J as IncomingMessageJob
    participant MR as MessageRouter
    participant FO as FlowOrchestrator
    participant FE as FlowEngine
    participant NH as NodeHandler
    participant MS as MessageSender

    U->>TG: sends a message
    TG->>WC: POST /webhook/{channel}/{hash}

    WC->>RD: GET webhook:{hash}
    RD-->>WC: {tenant_id, schema, assistant_id,<br/>channel_id, platform, secret_token}

    WC->>WC: verify signature (secret_token)
    WC->>RD: SET processed:{idempotency_key} NX EX 86400
    WC->>Q: dispatch IncomingMessageJob
    WC-->>TG: 200 OK (fast ack)

    Note over Q,J: Async, separate worker

    Q->>J: handle(InboundWebhookPayload)
    J->>J: TenantSwitcher::runForTenant(tenant)<br/>(sets TenantContext, switches search_path)
    J->>J: adapter.normalize(rawPayload)<br/>resolve assistant, contact, channel
    J->>J: log inbound message (Conversation capture)

    Note over J: Idempotency dedup already happened in the ingress,<br/>before the job was dispatched

    J->>MR: route(contact, incomingMessage, assistant, channel)

    Note over MR: 1. Command match (pre-lock, synchronous)
    MR->>MR: CommandMatcher.match(/reset, /cancel, ...)
    alt command matched
        MR->>MS: send(command response)
        MS->>TG: command reply
        MR-->>J: commandHandled (no lock)
    end

    Note over MR: 2. Typing indicator start
    MR->>TG: typing action

    Note over MR: 3. Lock acquisition (3 attempts, 2s apart)
    MR->>RD: LOCK session_lock:{tenant}:{contact}:{assistant} TTL=30s
    alt lock busy after all attempts
        MR->>MS: send(busy notice)
        MS->>TG: "busy"
        MR-->>J: dropped(lock_timeout), job is not retried
    end

    Note over MR: 4. Route by session state<br/>(staff takeover check, wake of a due paused session,<br/>then SessionStateRouter)
    MR->>FO: handle(contact, message, assistantId, trigger)

    FO->>FE: start(definition, contact) or resume(session, message)

    loop Execution loop (max 100 iterations)
        FE->>RD: refresh lock TTL (heartbeat, before each node)
        FE->>FE: resolve NodeHandler(type, version)
        FE->>NH: execute(nodeConfig, state, context)
        NH->>MS: send(OutboundMessage) (send_message and similar handlers)
        MS->>TG: message to the user
        NH-->>FE: NodeExecutionResult<br/>{status, sourceHandle, stateChanges, ...}
        FE->>FE: persist stateChanges + session status/current node<br/>(FlowSessionPersister)
        FE->>FE: next = edge(from=node, handle=sourceHandle).to
    end

    FE-->>FO: session ended / waiting for input / paused

    Note over MR: 5-6. Execute done, cleanup
    MR->>RD: RELEASE lock (token check)
    MR->>TG: stop typing
```

## Key classes

| Class | Path | Role |
|-------|------|------|
| `WebhookController` | `app/Domains/Webhook/Http/WebhookController.php` | Stateless ack: registry lookup, signature check, idempotency, dispatch |
| `IncomingMessageJob` | `app/Domains/Webhook/Jobs/IncomingMessageJob.php` | Tenant switch, normalisation, contact resolution, calls `MessageRouter` |
| `MessageRouter` | `app/Domains/Flow/Routing/MessageRouter.php` | Pipeline: commands, typing, lock, state routing, execute, cleanup |
| `FlowOrchestrator` | `app/Domains/Flow/Orchestration/FlowOrchestrator.php` | Finds or starts the session, access policy, optimistic retry; calls the engine |
| `FlowEngine` | `app/Domains/Flow/Services/FlowEngine.php` | Execution loop; public API `start`, `resume`, `runSession` (also `resumeFromNode`, `resumeAfterSubflow`) |
| `MessageSender` | `app/Domains/Messaging/MessageSender.php` | Delivery through the channel adapter, called by handlers via `MessageSenderInterface` |

## Important invariants

- **The engine does not send messages.** `NodeExecutionResult` carries no outbound messages; handlers
  such as `send_message` send them during `execute()` through `MessageSenderInterface`.
- **The ingress is stateless:** no database, only Redis and a dispatch, which is why it can live
  outside PHP. The optional Go gateway (`gateway/`) is the fast ingress; without it the same requests
  are served by PHP-FPM (`WebhookController`). The controller touches no Eloquent and no
  `TenantContext`.
- The channel hash resolves through Redis (`webhook:{hash}`); on a miss the registry falls back to
  the landlord `webhook_registry` table and re-caches it.
- Webhook idempotency: `processed:{idempotency_key}` (`SET NX`, 24 h) in the ingress, before dispatch.
- The session lock is one per (tenant, contact, assistant) triple, key
  `session_lock:{tenant}:{contact}:{assistant}` (`LockScope::key()`), TTL 30 s.
- Lock not acquired after 3 attempts 2 s apart: **busy notice** plus `dropped(lock_timeout)`; the job
  is **not** retried. The job's backoff (1, 2, 5, 10 s) applies only when the router catches
  `engine_lock_timeout` (the lock was lost or could not be taken during execution, not at the first
  acquire).
- Optimistic lock: `flow_sessions.version`, `UPDATE ... WHERE version = N`; `FlowOrchestrator`
  retries a `FlowConcurrencyException` up to 3 times.

## Related

- `.ai/knowledge/domains/flow/OVERVIEW.md` and `.ai/knowledge/domains/channel-ingress/OVERVIEW.md`
- [02-flow-engine-loop.md](02-flow-engine-loop.md), [03-tenant-context.md](03-tenant-context.md)
