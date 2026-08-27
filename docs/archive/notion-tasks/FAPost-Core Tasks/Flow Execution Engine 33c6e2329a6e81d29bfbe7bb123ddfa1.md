# Flow Execution Engine

> Архив Notion. Актуальная документация: [[README]]


Depends on: 10
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 5
Status: Готово
Task №: 11

Построить первый production-ready execution runtime Flow Engine. После этой задачи платформа способна реально прогонять flow end-to-end: запускать новую сессию через `start()`, продолжать активную через `resume()`, выполнять node loop до точки ожидания, завершения или ошибки, резолвить handler по паре (type, version) через in-memory registry, безопасно обновлять session через optimistic locking и писать execution trace в `flow_logs`.

## Архитектурные решения

**FlowEngine — stateless scoped service.** Не хранит mutable state в свойствах, не кеширует session/definition между вызовами, получает всё через аргументы. Регистрируется как scoped (per job/request) — semantic boundary чище, меньше риска Octane state leak.

**`resume()` принимает только `FlowSession` + `IncomingMessageData`.** Engine сам читает `current_node_id` из сессии — единственный source of truth. Caller не передаёт nodeId, иначе split authority. `IncomingMessageData` передаётся в handlers через `NodeExecutionContext`, может быть `null` при `start()`.

**Execute loop имеет обязательный max_iterations guard.** Configurable через `config('flow.execution.max_iterations', 100)`. При превышении: session → failed, log с `reason = max_iterations_exceeded`, бросается `FlowExecutionLimitExceededException`. Без этого любой цикл в графе или ошибочный sourceHandle повесит воркер.

**Условия выхода из цикла:** WAITING (input node ждёт пользователя), FAILED (handler exception), FINISHED (нет следующей ноды или явное завершение), DELAYED (delay node, ждём scheduler), MAX_ITERATIONS.

**`sourceHandle`, не `nextNodeId` в `NodeExecutionResult`.** Handler говорит `branch:yes` / `branch:no` / `default` — engine сам ищет edge по `(source_node_id, source_handle)` через `FlowGraphResolver`. Handler не знает о node ID соседей.

**Session persist после каждой ноды — обязательно.** Crash между нодами иначе ломает replay. Optimistic locking: `UPDATE ... WHERE version = N`. 0 rows affected → `FlowConcurrencyException` → retry на job уровне (Task 12).

**`flow_sessions` UPDATE и `flow_logs` INSERT — в одной DB transaction.** Разрыв между ними создаёт недопустимый audit trail. Fire-and-forget для logs — запрещено в Task 11.

**Гранулярность flow_logs:** один entry per node execution. Фиксирует `session_id`, `node_id`, `node_type`, `node_version`, `status`, `source_handle`, `next_node_id`, `resolved` (только фактически изменённые значения state, не весь state), `error`, `metadata`. Весь state в лог не пишем — таблица explode. Analytics_events в Task 11 не пишем.

**`FlowGraphResolver` — отдельный сервис.** Отвечает за: резолв entry node (explicit `entry_node_id` → первая нода в списке), поиск ноды по ID в definition, резолв следующей ноды по `(source_node_id, source_handle)`. Если следующей ноды нет — flow завершён.

**Entry node:** `entry_node_id` из definition если задан, иначе первая нода в `nodes` JSON.

**Initial system state при `start()`:** `system.started_at`, `system.flow_definition_id`, `system.contact_id`, `system.current_node`, `system.retry_count = 0`.

**`FlowSessionPersister` применяет stateChanges** из `NodeExecutionResult` к текущему state сессии (namespace.key формат: `flow.answer`, `system.retry_count`). После успешного UPDATE синхронизирует in-memory модель чтобы следующая итерация видела актуальный version без перечитки из БД.

**Retry и distributed lock — вне engine.** Lock живёт в pipeline (Task 12). Retry при `FlowConcurrencyException` — на job уровне. Engine не знает о lock.

**delay node в Task 11** просто возвращает DELAYED — scheduler integration в следующих задачах.

## Файловая структура

```
app/Domains/Flow/
├── Contracts/
│   └── FlowEngineInterface.php
├── Services/
│   ├── FlowEngine.php
│   ├── FlowGraphResolver.php
│   ├── FlowSessionPersister.php
│   └── FlowLogWriter.php
├── DTOs/
│   ├── NodeExecutionContext.php
│   └── NodeExecutionResult.php
├── Enums/
│   └── NodeExecutionStatus.php
└── Exceptions/
    ├── HandlerNotFoundException.php
    ├── FlowConcurrencyException.php
    ├── FlowExecutionLimitExceededException.php
    └── InvalidFlowGraphException.php
```

## Контракты и сигнатуры

```php
interface FlowEngineInterface
{
    public function start(
        FlowDefinition $definition,
        Contact $contact,
        array $initialState = []
    ): FlowSession;

    public function resume(
        FlowSession $session,
        IncomingMessageData $message
    ): FlowSession;
}

final readonly class NodeExecutionContext
{
    public function __construct(
        public FlowSession $session,
        public FlowDefinition $definition,
        public ?IncomingMessageData $incoming = null,
    ) {}
}

final readonly class NodeExecutionResult
{
    public function __construct(
        public NodeExecutionStatus $status,
        public ?string $sourceHandle = null,
        public array $stateChanges = [],
        public array $metadata = [],
        public ?string $errorMessage = null,
    ) {}
    // static constructors: completed(), waiting(), delayed(), finished(), failed()
}

enum NodeExecutionStatus: string
{
    case Completed = 'completed';
    case Waiting   = 'waiting';
    case Delayed   = 'delayed';
    case Failed    = 'failed';
    case Finished  = 'finished';
}

// FlowGraphResolver
public function resolveEntryNode(FlowDefinition $definition): string;
public function findNode(FlowDefinition $definition, string $nodeId): array;
public function resolveNextNode(FlowDefinition $definition, string $nodeId, string $sourceHandle): ?string;

// FlowSessionPersister
public function persist(FlowSession $session, NodeExecutionResult $result, ?string $nextNodeId): void;
// throws FlowConcurrencyException при 0 rows affected

// FlowLogWriter
public function write(FlowSession $session, array $node, NodeExecutionResult $result, ?string $nextNodeId): void;
public function writeFlowStart(FlowSession $session): void;
public function writeFlowEnd(FlowSession $session, string $reason): void;
```

## Execute loop (псевдокод)

```php
$iterations = 0;
$maxIterations = config('flow.execution.max_iterations', 100);

while (true) {
    if (++$iterations > $maxIterations) {
        // session → failed, log reason, throw FlowExecutionLimitExceededException
    }

    $node = $graphResolver->findNode($definition, $session->current_node_id);
    $handler = $registry->resolve($node['type'], $node['version'] ?? 1);
    // HandlerNotFoundException если handler не найден — это hard fail, не business case

    $result = $handler->handle($node, $context);

    $nextNodeId = null;
    if ($result->status === NodeExecutionStatus::Completed && $result->sourceHandle !== null) {
        $nextNodeId = $graphResolver->resolveNextNode($definition, $node['id'], $result->sourceHandle);
    }

    DB::transaction(function () use ($session, $result, $nextNodeId, $node) {
        $persister->persist($session, $result, $nextNodeId);
        $logWriter->write($session, $node, $result, $nextNodeId);
    });

    if (in_array($result->status, [WAITING, DELAYED, FAILED, FINISHED])) break;
    if ($nextNodeId === null) break; // нет следующей ноды — flow завершён
}
```

## Что НЕ входит в Task 11

Retry logic внутри engine — живёт в job layer (Task 12). Distributed lock — снаружи pipeline (Task 12). Delay scheduler integration — следующие задачи, delay node сейчас просто возвращает DELAYED. Analytics_events — только raw flow_logs. Filament UI.

## Тесты

Unit: `FlowGraphResolver` — резолв entry node, поиск ноды, резолв следующей ноды, отсутствие edge → null, невалидная структура → exception. `FlowSessionPersister` — применение stateChanges, version bump, 0 rows affected → `FlowConcurrencyException`, синхронизация in-memory модели. `FlowEngine::start()` — создание сессии с корректным initial state, вызов execute loop. `FlowEngine::resume()` — refresh сессии, продолжение с `current_node_id`. Execute loop: корректный выход по каждому из статусов, max_iterations guard → exception и failed session, завершение при отсутствии следующей ноды.

Integration: полный прогон flow из двух нод (send_message → input) через `start()`, проверка что session статус `waiting` после input ноды, проверка `flow_logs` записей, `resume()` с incoming message, проверка version bump в каждой итерации, `FlowConcurrencyException` при параллельном update.