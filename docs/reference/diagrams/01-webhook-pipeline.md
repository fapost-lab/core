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

    J->>MR: route(incomingMessage)

    Note over MR: 1. Idempotency check
    MR->>RD: SET NX processed:{update_id} EX 86400
    alt уже обработано
        RD-->>MR: 0 (exists)
        MR-->>J: skip (duplicate)
    end

    Note over MR: 2. Command match
    MR->>MR: CommandMatcher.match(/reset, /cancel)
    alt команда найдена
        MR->>MS: send(system response)
        MS->>TG: ответ команды
    end

    Note over MR: 3. Distributed lock
    MR->>RD: LOCK session:{tenant}:{contact}:{assistant} TTL=30s
    alt lock занят
        MR->>MS: send(busy_message)
        MS->>TG: «занят»
    end

    Note over MR: 4. Route по состоянию сессии
    MR->>FO: orchestrate(session, message)

    FO->>FE: execute(session, flowDefinition)

    loop Execution loop
        FE->>FE: resolve NodeHandler(type, version)
        FE->>NH: execute(nodeConfig, state, context)
        NH-->>FE: NodeExecutionResult<br/>{status, sourceHandle, stateChanges}
        FE->>FE: apply stateChanges
        FE->>FE: next = outputs[sourceHandle].next
        FE->>MS: отправка исходящих
        MS->>TG: сообщение пользователю
    end

    FE-->>FO: session завершена / ожидает ввода

    MR->>RD: RELEASE lock (Lua token check)
```

## Ключевые классы

| Класс | Путь | Роль |
|-------|------|------|
| `WebhookController` | `Http/Controllers/Webhook/` | Быстрый ack, dispatch job |
| `IncomingMessageJob` | `Jobs/` | Tenant setup, вызов MessageRouter |
| `MessageRouter` | `Domains/Flow/Routing/` | 6-шаговый pipeline (⏳ Phase D) |
| `FlowOrchestrator` | `Domains/Flow/Services/` | Сессия + запуск engine |
| `FlowEngine` | `Domains/Flow/Services/` | Execution loop, handler registry |
| `MessageSender` | `Domains/Messaging/Services/` | Отправка через channel adapter |

## Важные инварианты

- **Ingress stateless** (ADR-01, отменён): без БД, только Redis и dispatch — поэтому его можно вынести за пределы PHP. Роль быстрого ingress выполняет опциональный Go-гейтвей (`gateway/`); без него те же запросы обслуживает PHP-FPM
- `public_hash` → Redis — landlord DB не участвует в hot path
- Lock не получен → **busy notice**, не дроп: job уходит в backoff очередь
- Optimistic lock: `flow_sessions.version` — `UPDATE WHERE version = N`

## Связано с
- [[architecture/adr/01-octane-ingress-only|ADR-01]] — отменён; ingress-only обоснование сохранено как история
- [[09-message-routing-concurrency|ADR-09 Message Routing]] — concurrency & lock
- [[architecture/platform/10-message-pipeline|Platform: Message Pipeline]]
- [[specs/flow-engine/README|Flow Engine спека]]
