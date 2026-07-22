# Concurrency protection

> Архив Notion. Актуальная документация: [[08-concurrency-idempotency]]


Depends on: 11
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 5
Status: Готово
Task №: 12

## Состав

- Distributed lock per (tenant, contact, bot) Redis TTL=30s
- Optimistic lock retry при 0 rows affected
- Backoff queue при lock miss — не дроп

## Три уровня защиты

| Уровень | Механизм | TTL/поведение | 1 | Idempotency key Redis SET NX | TTL=24h, дроп дублей до очереди |
| --- | --- | --- | --- | --- | --- |
| 2 | Distributed lock на `session_lock:{tenant}:{contact}:{bot}` | TTL=30s, backoff | 3 | Optimistic locking `version` на `flow_sessions` | 0 rows → retry |

## Архитектурные контракты

- `FlowEngine` не зависит от Redis — никаких `Cache`, `Redis`, `Lock` внутри engine
- Distributed lock = инфраструктурный слой (`FlowExecutionGuardInterface`), вызывается из `FlowOrchestrator`, не из engine
- Optimistic conflict → `FlowConcurrencyException` из engine → retry с reload сессии из БД в orchestration-слое
- Lock miss → `$this->release($delay)` + `return` в той же очереди `flow.execution`, не дроп
- Lock scope = один execution pass, не lifecycle сессии
- Delay/input node → engine возвращает `Pause` → lock снимается в `finally` до паузы
- Follow-up job (`DelayedResumeJob`) берёт новый lock и валидирует `current_node_id === expectedNodeId` перед `resume()`

## File Structure

```
fapost/foundation/src/
  Flow/
    Contracts/
      FlowExecutionGuardInterface.php

app/
  Infrastructure/
    Flow/
      FlowExecutionGuard.php
      Exceptions/
        SessionLockTimeoutException.php
  Domains/
    Flow/
      Orchestration/
        FlowOrchestrator.php
  Jobs/
    IncomingMessageJob.php              # доработка существующего
```

## Interfaces & Class Signatures

### FlowExecutionGuardInterface

```php
// fapost/foundation/src/Flow/Contracts/FlowExecutionGuardInterface.php

interface FlowExecutionGuardInterface
{
    /**
     * Захватывает distributed lock и выполняет $callback внутри критической секции.
     * Lock снимается в finally — всегда.
     *
     * @throws SessionLockTimeoutException  если lock не удалось получить
     */
    public function run(
        string $tenantId,
        string $contactId,
        string $botId,
        Closure $callback,
    ): mixed;
}
```

### FlowExecutionGuard

```php
// app/Infrastructure/Flow/FlowExecutionGuard.php

final class FlowExecutionGuard implements FlowExecutionGuardInterface
{
    public function __construct(
        private readonly \Illuminate\Contracts\Cache\LockProvider $store,
        private readonly int $ttl = 30,
    ) {}

    public function run(string $tenantId, string $contactId, string $botId, Closure $callback): mixed
    {
        $key  = "session_lock:{$tenantId}:{$contactId}:{$botId}";
        $lock = $this->store->lock($key, $this->ttl);

        if (! $lock->get()) {
            throw new SessionLockTimeoutException($key);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
```

Зависимость на `LockProvider`, не на `Cache\Repository` — контракт гарантирует наличие `lock()`.

Биндинг в провайдере: `Cache::store('redis')` реализует `LockProvider`.

### FlowOrchestrator

```php
// app/Domains/Flow/Orchestration/FlowOrchestrator.php

final class FlowOrchestrator
{
    private const MAX_OPTIMISTIC_RETRIES = 3;

    public function __construct(
        private readonly FlowExecutionGuardInterface $guard,
        private readonly FlowEngineInterface         $engine,   // интерфейс, не конкретный класс
        private readonly FlowSessionRepository       $sessions,
    ) {}

    /**
     * Точка входа из IncomingMessageJob.
     *
     * @throws SessionLockTimeoutException   — пробрасывается, Job делает release с backoff
     * @throws FlowConcurrencyException      — если все optimistic retry исчерпаны
     */
    public function handle(Contact $contact, IncomingMessage $message, string $botId): void
    {
        $this->guard->run(
            tenantId:  $contact->tenant_id,
            contactId: $contact->id,
            botId:     $botId,
            callback:  fn() => $this->executeWithOptimisticRetry($contact, $message, $botId),
        );
    }

    private function executeWithOptimisticRetry(Contact $contact, IncomingMessage $message, string $botId): void
    {
        $attempt = 0;

        while (true) {
            try {
                // reload на каждой попытке — никогда не переиспользуем stale объект
                $session = $this->sessions->findActiveForContact($contact, $botId);

                $session === null
                    ? $this->engine->start($contact, $message, $botId)
                    : $this->engine->resume($session, $message);

                return;

            } catch (FlowConcurrencyException $e) {
                if (++$attempt >= self::MAX_OPTIMISTIC_RETRIES) {
                    throw $e;
                }
                usleep(50_000 * $attempt); // 50ms, 100ms, 150ms — не агрессивно
            }
        }
    }
}
```

### IncomingMessageJob — доработка

```php
// app/Jobs/IncomingMessageJob.php

final class IncomingMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    // backoff() не определяем — lock miss обрабатывается вручную через release()

    public function handle(
        TenantRepositoryInterface $tenants,
        ContactServiceInterface   $contacts,
        FlowOrchestrator          $orchestrator,
    ): void {
        $tenant = $tenants->findOrFail($this->tenantId);

        $tenant->runForTenant(function () use ($contacts, $orchestrator) {
            $contact = $contacts->findOrCreateChannelContact(/* ... */);

            try {
                $orchestrator->handle($contact, $this->message, $this->botId);
            } catch (SessionLockTimeoutException) {
                $delay = match ($this->attempts()) {
                    1 => 1,
                    2 => 2,
                    3 => 5,
                    default => 10,
                };
                $this->release($delay);
                return; // явное завершение — не продолжать выполнение
            }
            // FlowConcurrencyException после исчерпания retry → failed jobs
        });
    }
}
```

### SessionLockTimeoutException

```php
// app/Infrastructure/Flow/Exceptions/SessionLockTimeoutException.php

final class SessionLockTimeoutException extends RuntimeException
{
    public function __construct(string $lockKey)
    {
        parent::__construct("Could not acquire session lock: {$lockKey}");
    }
}
```

## Delayed Node — Lock Release Protocol

Контракт для задачи 13 (`delay` handler):

```
delay node handler:
  1. Сохранить сессию в статус waiting, зафиксировать current_node_id
  2. Запланировать DelayedResumeJob(expectedNodeId = session->current_node_id)
  3. Вернуть NodeExecutionResult::pause()
     → Engine получает Pause → сохраняет сессию → FlowOrchestrator выходит
     → lock снимается в finally FlowExecutionGuard

DelayedResumeJob::handle():
  1. Взять lock через FlowExecutionGuard (новый lock)
  2. Reload session из БД
  3. if session->current_node_id !== expectedNodeId → return (skip, stale)
  4. FlowEngine::resume()
```

## Service Provider Bindings

```php
$this->app->singleton(FlowExecutionGuardInterface::class, function ($app) {
    return new FlowExecutionGuard(
        store: Cache::store('redis')->getStore(),
        ttl:   30,
    );
});

$this->app->bind(FlowOrchestrator::class); // scoped, new instance per job
```

## What NOT To Do

- `FlowEngine` не импортирует `Cache`, `Redis`, `Lock` — вообще ничего из инфраструктуры Redis
- Не создавать отдельную очередь `flow.execution.retry` — избыточно на данном этапе
- `$lock->release()` — только в `finally`, никогда раньше
- При lock miss не вызывать `$this->fail()` — только `$this->release($delay)` + `return`
- При optimistic conflict не повторять на том же объекте `$session` — всегда reload из БД
- Не держать lock дольше одного execution pass
- Не определять `backoff()` в Job — конфликтует с ручным `release($delay)`, два механизма для одного сценария

## Tests

```
FlowExecutionGuardTest:
  ✓ lock acquired → callback выполняется, результат возвращается
  ✓ lock busy → SessionLockTimeoutException
  ✓ exception внутри callback → lock всё равно release (finally)

FlowOrchestratorTest:
  ✓ успешный старт нового flow (session === null)
  ✓ успешный resume существующей сессии
  ✓ FlowConcurrencyException на 1-й попытке → retry с reload из БД
  ✓ FlowConcurrencyException на всех MAX_OPTIMISTIC_RETRIES → пробрасывается
  ✓ SessionLockTimeoutException пробрасывается наверх, не поглощается

IncomingMessageJobTest:
  ✓ lock miss → release() с правильным delay, return (выполнение не продолжается)
  ✓ успешное выполнение → release() не вызывается
  ✓ attempts() влияет на delay: 1→1s, 2→2s, 3→5s, 4+→10s
```

## Code Review Checklist

- [ ]  `FlowExecutionGuard` зависит от `LockProvider`, не от `Cache\Repository`
- [ ]  `FlowExecutionGuard::run()` снимает lock в `finally` без исключений
- [ ]  `FlowOrchestrator` зависит от `FlowEngineInterface`, не от конкретного класса
- [ ]  `FlowOrchestrator` перечитывает сессию из БД на каждой попытке
- [ ]  `IncomingMessageJob` при `SessionLockTimeoutException` вызывает `release()` + `return`
- [ ]  `IncomingMessageJob` не определяет `backoff()` — единственный механизм backoff это ручной `release($delay)`
- [ ]  `DelayedResumeJob` валидирует `expectedNodeId` перед `resume()`
- [ ]  `FlowEngine` не содержит ни одного `use` из инфраструктуры Redis/Cache
- [ ]  phpat-правило: `FlowEngine` не зависит от `Infrastructure\Flow`