# 02. Worker: tenant switch, контакт, сессия, триггер

После постановки в очередь `IncomingMessageJob` делает всю доменную работу.

## Последовательность выполнения

Порядок в `IncomingMessageJob::handle()` строгий и важный (докблок класса,
`app/Domains/Webhook/Jobs/IncomingMessageJob.php:24-36`):

1. Создаётся `RuntimeTenant` из payload (`tenantId`, `schema`).
2. `TenantSwitcher::runForTenant(...)` переключает tenant schema.
3. `ChannelAdapterResolver` нормализует raw payload -> `IncomingMessage`.
4. Резолвятся assistant (`AssistantRepositoryInterface::findById`), контакт
   (`ContactService::findOrCreate`), channel-contact связь
   (`findOrCreateChannelContact`) и канал (`Channel::findOrFail`).
5. Входящее сообщение пишется в transcript (`ConversationLoggerInterface::log`) -
   до роутинга, чтобы сообщения, задропленные дальше под конкурентностью, всё
   равно попадали в историю.
6. Весь дальнейший pipeline - global commands, typing indicator, **lock**,
   session state, запуск flow, cleanup - делегируется в `MessageRouter::route(...)`.
7. Если `MessageRouter` вернул outcome `engine_lock_timeout` - job делает
   `release(...)` с backoff (1, 2, 5, 10 сек) (`IncomingMessageJob.php:122-145`).
   Остальные "dropped"-исходы (`lock_timeout`, `drop_busy`, `drop_silent`,
   `staff_handled`, `lock_lost`) не ретраятся: юзер либо уже получил busy-notice,
   либо дроп намеренно молчаливый.

`IncomingMessageJob` сам никакого lock не берёт - это устаревшее описание. Session
lock целиком живёт внутри `MessageRouter` (и `FlowExecutionGuard` для entry points
вне роутера, см. ниже).

### Что делает MessageRouter::route(...) (шаг 6)

`MessageRouter` (`app/Domains/Flow/Routing/MessageRouter.php:32-53`) выполняет
6-шаговый pipeline:

1. Global command match - без lock, синхронно (`/reset`, `/cancel` built-in +
   tenant `assistant.commands`, `CommandMatcher::match`).
2. Typing indicator start.
3. **Lock acquisition** с backoff retry (`LockAcquisitionPolicy::acquireWithRetry`,
   3 попытки × 2s).
4. Session state classification: сначала staff-takeover check, потом
   `SessionStateRouter::decide(...)`.
5. Flow execution через `FlowOrchestratorInterface::handle(...)`.
6. Cleanup: release lock, stop typing.

## Зачем lock и что его держит

Lock один - на triple (tenant, contact, assistant). Никаких "двух слоёв" в смысле
двух независимых lock'ов за один прогон нет. Ключ:

```
session_lock:{tenantId}:{contactId}:{assistantId}
```

(`LockScope::key()`, `app/Domains/Flow/Concurrency/LockScope.php:23-26`) - берётся
один раз за обработку входящего сообщения.

- **Основной держатель - `MessageRouter`**, на шаге 3 своего pipeline. Полученный
  `LockHandle` публикуется в `SessionLockRegistry`, поэтому:
  - `FlowExecutionGuard` (`app/Infrastructure/Flow/FlowExecutionGuard.php`),
    вызываемый глубже по стеку внутри orchestration, видит через
    `SessionLockRegistry::holds()`, что lock уже держит этот же worker, и просто
    выполняет callback без повторного acquire (`FlowExecutionGuard.php:49-52`) -
    он re-entrant, а не второй независимый слой;
  - `FlowEngine` продлевает TTL перед каждой нодой: `refreshSessionLock()` ->
    `LockHeartbeat::extend(...)` (`app/Domains/Flow/Services/FlowEngine.php:655-668`,
    вызывается из `executeLoop()` на :389, до `$handler->execute(...)`).
- **Для entry points, которые не идут через `MessageRouter`** -
  `FlowExecutionGuard::run(...)` сам берёт этот же lock scope с нуля (registry
  там пуст, повторного входа нет). Такие entry points: `DelayedSessionResumer`
  (`app/Domains/Flow/Orchestration/DelayedSessionResumer.php`),
  `ResumeTimedOutSendMessageNodeJob` и `StartFlowFromEventJob`
  (`app/Jobs/Flow/`).

Если lock не получен на шаге 3 `MessageRouter` - `DropPolicy::applyBusy(...)`,
outcome `lock_timeout` (busy-notice юзеру, ретрая нет). Если lock теряется во
время исполнения (heartbeat не смог продлить TTL) - `SessionLockLostException` /
`SessionLockTimeoutException`, outcome `lock_lost` / `engine_lock_timeout`
(`MessageRouter::route()`, :160-183); только `engine_lock_timeout` ретраится
джобой (см. выше).

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

| Класс                    | Метод                             | Роль                                                                                             |
|--------------------------|------------------------------------|---------------------------------------------------------------------------------------------------|
| `IncomingMessageJob`     | `handle`                           | Transport-level шаги (tenant/contact/channel), делегирует в `MessageRouter`                      |
| `MessageRouter`          | `route`                            | 6-шаговый pipeline: commands → typing → **lock** → session state → execute → cleanup             |
| `FlowSessionRepository`  | `findActiveForContact`, `cancel`   | Поиск/отмена сессии                                                                                |
| `TriggerResolver`        | `resolve`                          | Делегирует в `message/schedule/webhook/api` resolver                                              |
| `MessageTriggerResolver` | `resolve`                          | Ищет лучший keyword/phrase trigger                                                                 |
| `FlowOrchestrator`       | `handle`                           | Выбор ветки `resume/start/fallback`                                                                |
| `FlowExecutionGuard`     | `run`                              | Тот же session lock для entry points вне `MessageRouter`; re-entrant passthrough, если lock уже держит текущий worker |
