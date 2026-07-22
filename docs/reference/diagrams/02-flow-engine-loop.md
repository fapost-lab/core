# Flow Engine — цикл выполнения

Как engine проходит граф нод от старта до конца (или паузы на ввод).

```mermaid
flowchart TD
    START([Старт: FlowEngine::execute]) --> LOAD

    LOAD[Загрузить flow_definition\nпо flow_definition_id] --> RESOLVE_NODE

    RESOLVE_NODE[Взять current_node_id\nиз session.state] --> GET_NODE

    GET_NODE{Нода существует\nв графе?}
    GET_NODE -- нет --> ERR_MISSING([❌ NodeNotFoundException])
    GET_NODE -- да --> GET_HANDLER

    GET_HANDLER[NodeHandlerRegistry\n.resolve type + version] --> HANDLER_FOUND

    HANDLER_FOUND{Handler\nнайден?}
    HANDLER_FOUND -- нет --> ERR_HANDLER([❌ HandlerNotFoundException])
    HANDLER_FOUND -- да --> SPECIAL

    SPECIAL{Спец. тип ноды?}
    SPECIAL -- end --> END_NODE[EndNodeHandler\nустанавливает статус сессии]
    SPECIAL -- loop_end --> LOOP_END[Возврат к loop_node_id\nиз config]
    SPECIAL -- subflow --> SUBFLOW[SubflowNodeHandler\nсоздаёт дочернюю сессию\npause parent]
    SPECIAL -- нет --> EXECUTE

    END_NODE --> FINISH([✅ Сессия завершена])
    LOOP_END --> RESOLVE_NODE
    SUBFLOW --> PAUSE([⏸ Parent paused\nждёт child])

    EXECUTE[NodeHandler::execute\nnodeConfig, state, context] --> RESULT

    RESULT[NodeExecutionResult\nsourceHandle\nstateChanges\nmessages] --> WRITE_STATE

    WRITE_STATE[Применить stateChanges\nк session.state\nоптимистичный lock] --> SEND_MESSAGES

    SEND_MESSAGES{Есть messages\nдля отправки?}
    SEND_MESSAGES -- да --> SENDER[MessageSender::send\nкаждое сообщение]
    SEND_MESSAGES -- нет --> NEXT_EDGE
    SENDER --> NEXT_EDGE

    NEXT_EDGE[outputs\nsourceHandle .next] --> EDGE_EXISTS

    EDGE_EXISTS{Edge\nсуществует?}
    EDGE_EXISTS -- нет → end-like --> FINISH
    EDGE_EXISTS -- waiting_input --> WAIT([⏸ Ждём ввода пользователя\nsession.status = waiting_input])
    EDGE_EXISTS -- да --> UPDATE_NODE

    UPDATE_NODE[state.current_node_id\n= next_node_id] --> LOOP_CHECK

    LOOP_CHECK{Превышен\nmax_iterations?}
    LOOP_CHECK -- да --> ERR_BUDGET([❌ IterationBudgetException])
    LOOP_CHECK -- нет --> RESOLVE_NODE
```

## Спец. случаи engine (graph-aware навигация)

Engine делает special-case для трёх типов нод — handler остаётся graph-unaware:

| Тип | Поведение engine |
|-----|-----------------|
| `end` | Устанавливает `session.status` по `end_status` config |
| `loop_end` | Переходит к `loop_node_id` (денормализован при publish) |
| `subflow` | Создаёт дочернюю сессию, ставит parent в `paused_subflow` |

## Оптимистичный lock

```
UPDATE flow_sessions
  SET state = ?, version = version + 1
  WHERE id = ? AND version = ?  ← если не совпадает → retry
```

## Handler contract

Handler **не знает** о графе — только о своей конфигурации и state:

```php
interface NodeHandlerInterface {
    public function execute(
        array $nodeConfig,
        array $state,
        NodeExecutionContext $context
    ): NodeExecutionResult;
}
```

Возвращает `sourceHandle` (имя выхода), не `nextNodeId`. Engine сам резолвит следующую ноду.

## Связано с
- [[specs/flow-engine/README|Flow Engine спека]]
- [[specs/flow-engine/00-overview|00 State model]]
- [[10-state-writer-semantics|ADR-10 State Writer]]
- [[04-session-state-machine]]
