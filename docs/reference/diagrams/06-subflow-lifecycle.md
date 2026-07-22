# Subflow Lifecycle — запуск вложенного flow и возврат управления

Полный цикл выполнения subflow-ноды: от запроса контакта до resumed parent, включая routing входящих сообщений в дочернюю сессию.

```mermaid
sequenceDiagram
    actor U as Контакт
    participant MR as MessageRouter
    participant FO as FlowOrchestrator
    participant FE as FlowEngine
    participant SH as SubflowNodeHandler
    participant DB as flow_sessions
    participant EH as EndNodeHandler
    participant SR as SubflowResumer

    Note over U,SR: Фаза 1 — Parent flow достигает ноды subflow

    U->>MR: входящее сообщение
    MR->>FO: orchestrate(parentSession, message)
    FO->>FE: execute(parentSession)

    FE->>FE: resolve node type=subflow
    Note over FE: Спец. случай engine (graph-aware)

    FE->>SH: execute(subflowConfig, state, context)
    SH->>DB: CREATE child FlowSession\nparent_session_id = parent.id\nparent_resume_node_id = next_node_id\nflow_definition_id = subflow_flow_id
    DB-->>SH: childSession

    SH->>DB: UPDATE parentSession\nstatus = paused_subflow
    SH-->>FE: NodeExecutionResult\nsourceHandle = paused

    FE-->>FO: parent paused, child active
    Note over DB: parentSession.status = paused_subflow\nchildSession.status = active

    Note over U,SR: Фаза 2 — Взаимодействие внутри child flow

    U->>MR: сообщение (ответ на input в subflow)
    MR->>MR: ищет active сессию для\n(tenant, contact, assistant)
    Note over MR: Routing: active child приоритетнее paused parent
    MR->>FO: orchestrate(childSession, message)
    FO->>FE: execute(childSession)
    FE->>FE: обычное выполнение нод child flow
    FE-->>FO: child продолжается / ждёт ввода

    Note over U,SR: Фаза 3 — Child flow завершается (нода end)

    U->>MR: последнее сообщение в child
    MR->>FO: orchestrate(childSession, message)
    FO->>FE: execute(childSession)

    FE->>FE: resolve node type=end
    Note over FE: EndNodeHandler — спец. случай engine

    FE->>EH: execute(endConfig, state, context)
    Note over EH: Видит parent_session_id в контексте

    EH->>DB: UPDATE childSession\nstatus = ended\nend_status = success|cancelled|failed
    EH->>SR: resumeParent(childSession)

    SR->>DB: LOAD parentSession\nby parent_session_id
    SR->>DB: UPDATE parentSession\nstatus = active\ncurrent_node_id = parent_resume_node_id

    Note over SR: V1: output_mapping игнорируется\n(передача переменных child→parent — V1.1)

    SR->>FE: execute(parentSession)
    Note over FE: Parent продолжает с parent_resume_node_id\nhandle = end_status дочерней сессии

    FE->>FE: outputs[end_status].next → следующая нода parent
    FE-->>FO: parent продолжает выполнение

    FO-->>U: ответ из parent flow
```

## Ключевые классы

| Класс | Путь | Роль |
|-------|------|------|
| `SubflowNodeHandler` | `Domains/Flow/Handlers/` | Создаёт child сессию, ставит parent в `paused_subflow` |
| `EndNodeHandler` | `Domains/Flow/Handlers/` | При наличии `parent_session_id` — триггерит resume parent |
| `SubflowResumer` / `DefaultSubflowResumer` | `Domains/Flow/Services/` | Загружает parent, обновляет статус, перезапускает engine |
| `FlowEngine` | `Domains/Flow/Services/` | Special-case по типу `subflow` и `end` (graph-aware навигация) |
| `CallGraphValidator` | `Domains/Flow/Services/` | Валидирует глубину вложенности при publish (max 3 уровня) |

## Важные инварианты

- **Routing приоритет:** пока child сессия active — все сообщения от контакта роутятся в child, не в parent
- **flow_definition_id snapshot:** child сессия стартует с `flow_definition_id` subflow — снимок зафиксирован, не меняется
- **`parent_resume_node_id`** денормализуется в child при создании — parent знает куда вернуться без lookup в граф
- **Глубина вложенности:** максимум 3 уровня (`A → B → C → D` запрещено), проверяется `CallGraphValidator` при publish, не в runtime
- **V1 — output_mapping не реализован:** переменные из child state в parent не передаются автоматически; реализуется в V1.1
- **`end_status` → handle:** parent resume использует `end_status` дочерней сессии как имя выхода (`success` / `cancelled` / `failed`) для резолвинга следующей ноды

## Связано с
- [[11-subflow-composition|ADR-11 Subflow Composition]]
- [[specs/flow-engine/nodes/08-subflow|Нода subflow]]
- [[04-session-state-machine]] — paused_subflow статус
- [[02-flow-engine-loop]] — engine-level навигация
- [[diagrams/02-flow-engine-loop]] — диаграмма execution loop
