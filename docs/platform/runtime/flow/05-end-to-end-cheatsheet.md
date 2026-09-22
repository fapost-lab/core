# 05. End-to-End Cheatsheet

Короткий "боевой" конспект для on-call и быстрого онбординга.

## E2E цепочка в 14 шагах

1. Провайдер шлёт webhook на `/webhook/{channel}/{hash}`.
2. `WebhookController` резолвит hash -> registry entry.
3. Проверяет подпись.
4. Делает dedup по `processed:{idempotency_key}`.
5. Кладёт `IncomingMessageJob` в `flow.execution`.
6. Worker переключается в tenant schema (`TenantSwitcher`).
7. Адаптер нормализует payload -> `IncomingMessage`.
8. `ContactService` находит/создаёт контакт и channel-contact связь - это происходит
   до lock, ещё в `IncomingMessageJob`.
9. `MessageRouter::route(...)` матчит global command (`/reset`, `/cancel`) -
   если найдена, выполняется без lock и pipeline на этом заканчивается
   (сессия помечается `terminated_by_user`, а не `cancelled`).
10. Иначе - typing indicator, затем `MessageRouter` берёт lock
    `session_lock:{tenant}:{contact}:{assistant}`.
11. `FlowSessionRepository` ищет активную сессию; `SessionStateRouter` решает
    resume / start / drop по её статусу.
12. Если сессии нет (или она в terminal-статусе) - `TriggerResolver` ищет trigger;
    иначе идём в resume.
13. `FlowOrchestrator` запускает `FlowEngine::start` или `FlowEngine::resume`;
    `FlowEngine` продлевает lock TTL перед каждой нодой (`refreshSessionLock()`).
14. `SendMessageNodeHandler` -> `FlowMessageSender` -> провайдер; на выходе из
    `MessageRouter` lock освобождается.

## Где чаще всего "ломается"

| Симптом                    | Вероятная зона                                      |
|----------------------------|-----------------------------------------------------|
| Webhook есть, но job нет   | ingress dedup или signature                         |
| Job есть, но сессии нет    | trigger не найден + нет default flow                |
| Сессия есть, но нет ответа | ошибка в `send_message` handler или outbound sender |
| Много retry/дубликатов     | lock contention (`session_lock:*`)                  |
| "Зависла" в ожидании       | `waiting_input` без релевантного входящего payload  |

## Минимальный набор проверки

1. Есть ли запись `flow_sessions` для контакта/assistant?
2. Какой у неё `status` и `current_node_id`?
3. Есть ли свежие `flow_logs` для этой `session_id`?
4. Был ли dedup hit по inbound событию?
5. Есть ли активный `channel_contact` + `channels.is_active = true`?

## Ссылки на основные файлы кода

- `app/Domains/Webhook/Http/WebhookController.php`
- `app/Domains/Webhook/Jobs/IncomingMessageJob.php`
- `app/Domains/Flow/Orchestration/FlowOrchestrator.php`
- `app/Domains/Flow/Services/FlowEngine.php`
- `app/Domains/Flow/Services/FlowSessionPersister.php`
- `app/Domains/Flow/Services/FlowMessageSender.php`
- `app/Domains/Flow/Services/FallbackMessageService.php`
