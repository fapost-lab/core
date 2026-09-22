# FlowSession — жизненный цикл сессии

Statechart состояний `FlowSession` от создания до завершения, включая ожидание ввода, вложенные subflow и аварийный выход.

```mermaid
stateDiagram-v2
    [*] --> pending : FlowOrchestrator создаёт сессию

    pending --> active : Engine начинает выполнение\nflow_definition_id зафиксирован

    active --> waiting_input : InputNodeHandler\nнода ждёт ответа пользователя

    waiting_input --> active : Пришёл ответ контакта\n(MessageRouter роутит в сессию)

    waiting_input --> ended : Timeout (no_response)\nили превышен retry_limit

    active --> paused : Нода вернула Delayed\n(например, delay-нода)

    active --> paused_subflow : SubflowNodeHandler\nсоздана дочерняя сессия\nparent_session_id установлен в child

    paused_subflow --> active : Дочерняя сессия завершена\n(DefaultSubflowResumer резолвит next node)\nParent resumed с handle end_status

    paused_subflow --> completed : Дочерняя сессия завершена\nresumeAfterSubflow не находит\nследующую ноду в parent-графе

    paused_subflow --> expired : SubflowTimeoutSweeper\nродитель осиротел (нет живого child)\nи expires_at истёк

    active --> completed : Граф исчерпан без явной end-ноды\n(NodeExecutionStatus::Finished)

    active --> ended : EndNodeHandler выполнен\n(end_status определяет финальный цвет)

    active --> failed : Неперехваченное исключение\nв engine или handler

    waiting_input --> failed : Неперехваченное исключение\nпри обработке ответа

    paused_subflow --> failed : Дочерняя сессия упала\nс неперехваченным исключением

    active --> terminated_by_user : GlobalCommandExecutor\n(/reset-подобная команда)

    waiting_input --> terminated_by_user : GlobalCommandExecutor\n(/reset-подобная команда)

    state ended {
        [*] --> success : end_status = success
        [*] --> cancelled : end_status = cancelled
        [*] --> failed : end_status = failed
    }

    ended --> [*]
    completed --> [*]
    failed --> [*]
    expired --> [*]
    terminated_by_user --> [*]
```

> `cancelled` — статус есть в enum `FlowSessionStatus` и в CHECK-constraint'е `flow_sessions`,
> задокументирован в `FlowSessionRepositoryInterface::cancel()` как предназначенный для
> reset-команд, но на момент написания ни один вызывающий код в `app/` его не использует —
> текущий `GlobalCommandExecutor` переводит сессию в `terminated_by_user`. Он не показан на
> диаграмме, так как для него не найдено ни одного реального перехода в коде. `paused`
> показан только со входящим переходом: код, который выводит сессию обратно из `paused`,
> в `app/Domains/Flow` не найден с уверенностью — `DelayedSessionResumer` проверяет статус
> `waiting_input`, а не `paused`, так что исходящий переход не подтверждён и не указан.

## Ключевые поля FlowSession

| Поле                    | Тип        | Назначение                                                                                  |
| ----------------------- | ---------- | ------------------------------------------------------------------------------------------- |
| `flow_definition_id`    | ULID       | Фиксируется при старте, **не меняется** до конца сессии (snapshot)                          |
| `status`                | enum       | Текущее состояние: `pending`, `active`, `waiting_input`, `paused`, `paused_subflow`, `completed`, `ended`, `failed`, `cancelled`, `expired`, `terminated_by_user` |
| `end_status`            | enum\|null | Финальный статус: `success`, `cancelled`, `failed` — только когда `status = ended`          |
| `state`                 | JSONB      | Все переменные сессии (namespaced: `system.*`, `flow.*`, `rag.*`)                           |
| `version`               | int        | Optimistic lock: `UPDATE WHERE version = N` защищает от concurrent writes                   |
| `parent_session_id`     | ULID\|null | Ссылка на parent для subflow — null для top-level сессий                                    |
| `parent_resume_node_id` | ULID\|null | Нода в parent, с которой продолжится выполнение после subflow                               |

## Важные инварианты

- `flow_definition_id` неизменяем после создания — сессия всегда выполняется по своему snapshot
- Routing входящих сообщений: пока существует active child — MessageRouter направляет в child, не в parent
- `end_status` определяет handle для resume parent: `success` / `cancelled` / `failed` → разные выходы в parent графе
- Вложенность subflow: максимум 3 уровня, проверяется `CallGraphValidator` при publish
- Optimistic lock (`version`) + distributed lock (Redis) — два независимых уровня защиты от concurrent execution

## Связано с
- [[specs/flow-engine/00-overview|00 State model]]
- [[10-state-writer-semantics|ADR-10 State Writer]]
- [[11-subflow-composition|ADR-11 Subflow]] — paused_subflow статус
- [[06-subflow-lifecycle]]
- [[specs/flow-engine/README]] — обзор flow engine
- [[06-flow-engine]] — flow engine архитектура
- [[diagrams/02-flow-engine-loop]] — диаграмма execution loop
