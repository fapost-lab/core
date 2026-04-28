# 03. Flow Engine: исполнение нод и отправка сообщений

Этот файл описывает, что происходит после решения orchestration "какую сессию/flow выполнять".

## Start vs Resume

- `FlowEngine::start(FlowDefinition $definition, Contact $contact, array $initialState = [])`
    - создаёт новую запись `flow_sessions`,
    - ставит `current_node_id` на entry node,
    - инициализирует системный state,
    - запускает execution loop.

- `FlowEngine::resume(FlowSession $session, IncomingMessage $message)`
    - перечитывает session + definition + contact,
    - запускает execution loop с входящим сообщением.

## Execution loop (сердце движка)

`FlowEngine::executeLoop(...)` повторяет цикл:

1. Проверяет лимит итераций (`flow.execution.max_iterations`).
2. Берёт текущую ноду по `session.current_node_id`.
3. Резолвит handler по `(type, version)` из `NodeHandlerRegistry`.
4. Формирует `NodeExecutionContext`:
    - tenant/contact/session/node ids,
    - idempotencyKey,
    - platform,
    - resolvedLanguage,
    - incoming (только на первом шаге resume).
5. Вызывает `handler->execute(...)`.
6. Определяет следующую ноду по `sourceHandle` через граф.
7. В транзакции:
    - применяет side-effects к контакту,
    - сохраняет сессию через `FlowSessionPersister`,
    - пишет `flow_logs`,
    - регистрирует analytics event after-commit.
8. Останавливается при статусах `waiting`, `delayed`, `failed`, `finished` или при отсутствии next node.

## Как меняется статус сессии

`FlowSessionPersister::resolveColumnPatch(...)`:

| NodeExecutionStatus            | Что пишется в `flow_sessions`                 |
|--------------------------------|-----------------------------------------------|
| `Executed` + есть `nextNodeId` | `status=active`, `current_node_id=nextNodeId` |
| `Executed` + нет next          | `status=completed`, `current_node_id=null`    |
| `Waiting`                      | `status=waiting_input`                        |
| `Delayed`                      | `status=paused`                               |
| `Failed`                       | `status=failed`                               |
| `Finished`                     | `status=completed`, `current_node_id=null`    |

Все обновления проходят через optimistic lock `saveWithOptimisticLock()` по полю `version`.

## Где реально отправляется сообщение пользователю

### 1) Handler уровня flow

`SendMessageNodeHandler::execute(...)`:

- нормализует config (`content_type`, keyboard, media),
- переводит multilingual поля через `ContentTranslatorInterface`,
- применяет шаблоны (`TemplateResolver`),
- вызывает `MessageSenderInterface::send(...)`,
- сохраняет id отправленного сообщения в `state[system.sent_messages]`.

### 2) Доменный sender

`FlowMessageSender::send(...)`:

1. Находит `FlowSession` и `assistant_id`.
2. Ищет активный `ChannelContact` контакта для этого assistant.
3. Собирает `OutboundMessage` (chatId, token, channelType, payload, metadata).
4. Вызывает общий `OutboundMessageSenderInterface`.
5. На ошибке кидает exception (flow должен знать, что отправка не удалась).

## Важные idempotency точки

| Уровень             | Механизм                                                  |
|---------------------|-----------------------------------------------------------|
| Webhook ingress     | `processed:{idempotency_key}` в Redis                     |
| Session execution   | distributed lock `session_lock:*`                         |
| Session persistence | optimistic lock по `flow_sessions.version`                |
| Outbound send       | `idempotencyKey` в `OutboundMessage` (`session:node:key`) |

## Практический смысл для дебага

- Если сообщение "не уходит", проверяй сначала `flow_logs` для `send_message` ноды, потом `FlowMessageSender`.
- Если сессия "залипла", смотри `status`, `current_node_id`, `version` и последние `flow_logs`.
- Если видишь конфликты конкуренции, ищи retries по `FlowConcurrencyException` и lock misses.
