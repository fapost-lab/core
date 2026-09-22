# Webhook Pipeline — входящее сообщение

Полный путь сообщения от Telegram до ответа бота.

```mermaid
sequenceDiagram
    actor U as Пользователь
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

    U->>TG: отправляет сообщение
    TG->>WC: POST /webhook/telegram/{hash}

    WC->>RD: GET webhook_registry:{hash}
    RD-->>WC: {tenant_id, assistant_id,<br/>channel_id, secret_token}

    WC->>WC: verify secret_token
    WC->>Q: dispatch IncomingMessageJob
    WC-->>TG: 200 OK (быстрый ack)

    Note over Q,J: Async — отдельный worker

    Q->>J: process(message, context)
    J->>J: TenantContext::set(tenant_id)
    J->>J: switch DB schema

    Note over J: Idempotency dedup уже прошёл в WebhookController,<br/>до dispatch job (processed:{idempotency_key})

    J->>MR: route(contact, incomingMessage, assistant, channel)

    Note over MR: 1. Command match (pre-lock, synchronous)
    MR->>MR: CommandMatcher.match(/reset, /cancel)
    alt команда найдена
        MR->>MS: send(system response)
        MS->>TG: ответ команды
        MR-->>J: commandHandled (без lock)
    end

    Note over MR: 2. Typing indicator start
    MR->>TG: indicateProcessing(chatId)

    Note over MR: 3. Lock acquisition (backoff retry: 3×2s)
    MR->>RD: LOCK session_lock:{tenant}:{contact}:{assistant} TTL=30s
    alt lock занят после всех попыток
        MR->>MS: send(busy_message)
        MS->>TG: «занят»
        MR-->>J: dropped(lock_timeout) — job не ретраится
    end

    Note over MR: 4. Route по состоянию сессии<br/>(staff takeover check, затем SessionStateRouter)
    MR->>FO: orchestrate(session, message)

    FO->>FE: execute(session, flowDefinition)

    loop Execution loop
        FE->>FE: refresh lock TTL (heartbeat, before each node)
        FE->>FE: resolve NodeHandler(type, version)
        FE->>NH: execute(nodeConfig, state, context)
        NH-->>FE: NodeExecutionResult<br/>{status, sourceHandle, stateChanges}
        FE->>FE: apply stateChanges
        FE->>FE: next = outputs[sourceHandle].next
        FE->>MS: отправка исходящих
        MS->>TG: сообщение пользователю
    end

    FE-->>FO: session завершена / ожидает ввода

    Note over MR: 5-6. Execute done, cleanup
    MR->>RD: RELEASE lock (Lua token check)
    MR->>TG: stopProcessing (typing)
```

## Ключевые классы

| Класс | Путь | Роль |
|-------|------|------|
| `WebhookController` | `Http/Controllers/Webhook/` | Быстрый ack, dispatch job |
| `IncomingMessageJob` | `Jobs/` | Tenant setup, вызов MessageRouter |
| `MessageRouter` | `Domains/Flow/Routing/` | 6-шаговый pipeline: commands → typing → lock → state → execute → cleanup |
| `FlowOrchestrator` | `Domains/Flow/Services/` | Сессия + запуск engine |
| `FlowEngine` | `Domains/Flow/Services/` | Execution loop, handler registry |
| `MessageSender` | `Domains/Messaging/Services/` | Отправка через channel adapter |

## Важные инварианты

- **Ingress stateless** (ADR-01, отменён): без БД, только Redis и dispatch — поэтому его можно вынести за пределы PHP. Роль быстрого ingress выполняет опциональный Go-гейтвей (`gateway/`); без него те же запросы обслуживает PHP-FPM
- `public_hash` → Redis — landlord DB не участвует в hot path
- Session lock — один на triple (tenant, contact, assistant), ключ
  `session_lock:{tenant}:{contact}:{assistant}` (`LockScope::key()`)
- Lock не получен после retry (3×2s) → **busy notice** + `dropped(lock_timeout)`,
  job **не** ретраится. Backoff-очередь (1, 2, 5, 10 сек) срабатывает только
  когда `MessageRouter` ловит `engine_lock_timeout` (lock потерян во время
  исполнения, а не при первичном acquire)
- Optimistic lock: `flow_sessions.version` — `UPDATE WHERE version = N`

## Связано с
- [[architecture/adr/01-octane-ingress-only|ADR-01]] — отменён; ingress-only обоснование сохранено как история
- [[09-message-routing-concurrency|ADR-09 Message Routing]] — concurrency & lock
- [[architecture/platform/10-message-pipeline|Platform: Message Pipeline]]
- [[specs/flow-engine/README|Flow Engine спека]]
