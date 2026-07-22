# 02. Worker: tenant switch, контакт, сессия, триггер

После постановки в очередь `IncomingMessageJob` делает всю доменную работу.

## Последовательность выполнения

Порядок в `IncomingMessageJob::handle()` строгий и важный:

1. Создаётся `RuntimeTenant` из envelope (`tenantId`, `schema`).
2. `TenantSwitcher::runForTenant(...)` переключает tenant schema.
3. `ChannelAdapterResolver` нормализует raw payload -> `IncomingMessage`.
4. Берётся distributed lock на ключ:
   `session_lock:{tenantId}:{platform}:{externalUserId}:{assistantId}`.
5. `ContactService::findOrCreate(...)` ищет/создаёт контакт.
6. `findOrCreateChannelContact(...)` привязывает контакт к каналу.
7. `FlowSessionRepository::findActiveForContact(...)` ищет активную сессию.
8. Если сообщение `/start|/reset|/stop` и сессия есть - `cancel(...)`.
9. Если активной сессии нет - `TriggerResolver::resolve(...)` ищет message trigger.
10. `FlowOrchestrator::handle(...)` решает: `resume`, `start`, или fallback-message.

## Зачем lock и почему два слоя

Есть два lock-слоя:

1. В `IncomingMessageJob` (до DB writes, ключ включает platform + externalUserId).
2. В `FlowExecutionGuard` (tenant/contact/assistant lock вокруг orchestration).

Практически это защищает от race-condition, когда несколько событий одного контакта приходят почти одновременно.

Если lock не получен:

- job делает `release(...)` с backoff (1, 2, 5, 10 сек),
- событие не теряется.

## Поиск активной сессии

`FlowSessionRepository::findActiveForContact(...)` фильтрует:

- `tenant_id = contact.tenant_id`
- `assistant_id = ...`
- `contact_id = ...`
- `status IN ('active', 'waiting_input')`
- `latest(updated_at)`

Важно: `paused` тут **не** считается активной сессией для resume в этом потоке.

## Что происходит, если сессии нет

`FlowOrchestrator`:

1. Пытается взять flow из resolved trigger (`$trigger->flowId`).
2. Если trigger не найден - берёт `CurrentAssistant->default_flow_id`.
3. Ищет актуальный definition (`findLatestActiveByFlowId`).
4. Если definition найден - `FlowEngine::start(...)`.
5. Если не найден - отправляет fallback message (если он настроен у assistant).

## Fallback путь

`FallbackMessageService::send(...)`:

- выбирает активный канал контакта для данного `assistant_id`,
- отправляет plain text через общий outbound sender,
- работает в best-effort режиме (ошибка отправки не должна уронить обработку inbound).

## Ключевые классы

| Класс                    | Метод                            | Роль                                                 |
|--------------------------|----------------------------------|------------------------------------------------------|
| `IncomingMessageJob`     | `handle`                         | Главный worker pipeline                              |
| `FlowSessionRepository`  | `findActiveForContact`, `cancel` | Поиск/отмена сессии                                  |
| `TriggerResolver`        | `resolve`                        | Делегирует в `message/schedule/webhook/api` resolver |
| `MessageTriggerResolver` | `resolve`                        | Ищет лучший keyword/phrase trigger                   |
| `FlowOrchestrator`       | `handle`                         | Выбор ветки `resume/start/fallback`                  |
| `FlowExecutionGuard`     | `run`                            | Distributed lock вокруг orchestration                |
