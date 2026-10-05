# ADR-10 — State Writer Semantics

> **Superseded in part (2026-10).** What the code does differs from the interface names used below:
>
> - `ScopedStateWriterInterface` was never created. Session writes go through `NodeExecutionResult::stateChanges` and
>   `FlowSessionPersister`; contact writes go through `ContactWriter`.
> - History is `HistoryWriterInterface`, `DefaultHistoryWriter`, `NoOpHistoryWriter` and `HistoryWriterFactory` in
>   `app/Domains/Flow/History/`, not `HistoryLoggerInterface` / `DefaultHistoryLogger` / `NoOpHistoryLogger`.
> - The Core `StateReader` / `StateWriter` layer was deleted (see `.ai/knowledge/adr/0002-retire-core-state-primitives.md`);
>   `ScopedStateReader` reads directly.
> - History retention is not implemented: `flow_session_history` accumulates with no pruning (the V1.x item stays open).

**Status:** Accepted
**Date:** Апрель 2026
**Контекст:** FaPost Phase 2 — Flow Engine
**Связанные документы:** `docs/reference/specs/flow-engine/`, `docs/platform/architecture/adr/09-message-routing-concurrency.md`, Platform Architecture v2.2

---

## Context

Flow Engine выполняет ноды которые читают и пишут state. Несколько источников writes:

- `input` — пользовательский ответ
- `assign` — вычисленное значение
- `call` — result_mapping в state
- `rag_query` — структурированный результат в `rag.*`
- `system.*` — engine metadata (current_node, attempts, started_at)
- `subflow` start/return — engine orchestration

Несколько target storages:

- `contact.*` — Contact модель (columns + JSONB attributes)
- `flow.*`, `system.*`, `rag.*`, `call.*` — `flow_sessions.state` JSONB
- `module.*` — read-only через DataAccessor (внешние таблицы модулей)

Возникали вопросы:

1. Накапливаются ли writes per-нода (batch flush) или применяются immediately?
2. Транзакционная граница между Contact и Session writes
3. Один writer-фасад или explicit injection of multiple writers
4. Read-after-write semantics, особенно для module.*
5. Optimistic-lock retry: engine или handler ответственен
6. История изменений state как separate concern

После обсуждения принято **упрощение**: пишем immediately, рассинхрон между Contact и Session допустим, retry заменит. История как отдельная opt-in feature.

## Decision

**Все writes применяются immediately в момент выполнения операции внутри handler. Никакого batch flush, никакого pending cache.**

Outline:

1. **Immediate writes:** каждая операция изменения state идёт прямо в storage
2. **Independent transactions:** Contact write и Session save — раздельные операции
3. **Engine retry on optimistic lock conflict:** handler должен быть idempotent at single-retry level (см. ADR Message Routing)
4. **Module reads без кеширования** — каждый read = вызов DataAccessor
5. **History logging as opt-in feature** — флаг на уровне flow_definition
6. **Parent/child sessions логируют независимо** — каждая session со своим flag
7. **Subflow navigation в history** — parent логирует subflow_started/returned events для traceability

## Rationale

### Immediate writes

Альтернатива — pending cache внутри ноды + flush в конце — добавляет complexity без реальной пользы:

- Большинство нод делают 1-2 write операции
- Read-after-write кейсы редки (`flow.counter = flow.counter + 1` дважды в одном assign)
- В редких кейсах handler может прочитать только что записанное значение из storage напрямую

Immediate writes:
- Простой mental model: "написал → значение в БД"
- Нет surprise behaviour между «pending» и «flushed» state
- Меньше кода, меньше bugs

### Independent transactions

Contact и Session — разные таблицы. Объединение в одной транзакции даёт atomicity, но:

- Long transactions = lock contention (HR sync, Filament edits Contact)
- Crash mid-write редко в реальности
- Retry семантика делает рассинхрон самокорректирующимся

Альтернативно — двухфазное применение (Contact first, then Session). Но это complexity.

**В V1 — независимые транзакции.** Каждая операция write — атомарна сама по себе. Если flow прервётся — Contact уже обновлён, retry повторно execute ноды и применит то же значение (idempotent через перезапись).

### Engine retries, handler идемпотентен

Optimistic lock conflict обрабатывается engine. Handler не думает о версионировании.

Это согласуется с ADR Message Routing (раздел Idempotency Contract): все node handlers обязаны быть safe to retry. Engine retry = одна дополнительная execution max.

### Module reads без кеша

`module.*` — read-only namespace через DataAccessor. Нет writes → pending cache бессмысленен.

Cache на reads (между two reads of same path в одной ноде):
- Не помогает correctness (canonical data может измениться)
- Преждевременная оптимизация для редкого паттерна

В V1 — каждый read через accessor. Если performance issue появится — добавим caching на уровне DataAccessor implementation, transparent для engine.

### History как opt-in feature

Логирование изменений state — это **отдельная feature**, не core engine concern. Включается флагом на flow_definition.

Reasoning:
- Не все flows требуют истории (быстрые transactional короткие диалоги)
- Storage cost логов — реальный (рост таблицы быстрый)
- Privacy: некоторые flows не должны хранить отдельную копию (PII concerns)
- Per-flow гранулярность даёт правильный default — выключено

### Parent/child независимое логирование

Subflow lifecycle — два разных session row. Каждая session работает по своему flow_definition. Каждый flow_definition имеет свой logging флаг.

Не наследуем между parent и child. Это:
- Соответствует архитектурному принципу subflow как самостоятельного flow
- Даёт authors flow контроль над своими логами
- Позволяет смешанные сценарии (parent логирует, child нет)

### Parent navigation через events

Чтобы parent history была понятна — записываем «subflow_started» и «subflow_returned» events. Без деталей внутренних нод child (те живут в child history).

Связь parent → child через `child_session_id` в event metadata. Любой анализ может перейти на child history по этому id.

## Contracts

### ScopedStateWriterInterface

Living в `fapost/foundation`:

```php
namespace Fapost\Foundation\Contracts\Flow\State;

interface ScopedStateWriterInterface
{
    /**
     * Write value to path. Applied immediately to storage.
     *
     * Path namespace determines storage:
     *  - contact.* → Contact model (columns or JSONB attributes)
     *  - flow.* → session.state.flow
     *  - rag.* → session.state.rag (only rag_query handler)
     *  - call.* → session.state.call (only call handler)
     *  - system.* → session.state.system (only engine)
     *
     * Reserved namespaces (module.*, contact.meta.*, contact.id, ...) - throw.
     *
     * @throws ReservedPathException
     * @throws StructuralConflictException — leaf vs group conflict in JSONB
     * @throws StateWriteException — storage failure
     */
    public function write(string $path, mixed $value): void;
}
```

### ScopedStateReaderInterface

```php
namespace Fapost\Foundation\Contracts\Flow\State;

interface ScopedStateReaderInterface
{
    /**
     * Read value from path. Returns current storage value.
     *
     * Resolution:
     *  - contact.* → Contact model resolver
     *  - flow.*, rag.*, call.*, system.* → session.state path
     *  - module.* → DataAccessor
     *
     * Returns null if path not found (no exception).
     */
    public function read(string $path): mixed;
}
```

### Implementation Hierarchy

Один facade per execution scope, internal routing:

```
ScopedStateWriter (facade)
    ├── ContactWriter (handles contact.*)
    │     ├── Column writes (id, channel_id, etc — but reserved)
    │     └── JSONB attributes writes (with structural validation)
    └── SessionStateWriter (handles flow.*, rag.*, call.*, system.*)
          └── JSONB session.state writes
```

Handler видит только `ScopedStateWriterInterface`. Routing — implementation detail.

### NodeExecutionContext

Updated from Nodes V1 spec (NodeExecutionResult упрощён, см. ниже):

```php
final class NodeExecutionContext
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly ContactInterface $contact,
        public readonly FlowSessionInterface $session,
        public readonly FlowDefinitionInterface $definition,
        public readonly array $nodeConfig,
        public readonly ScopedStateReaderInterface $stateReader,
        public readonly ScopedStateWriterInterface $stateWriter,
        public readonly ExpressionEngineInterface $expressionEngine,
        public readonly HistoryLoggerInterface $historyLogger,
    ) {}
}
```

### NodeExecutionResult Simplified

Из Nodes V1 убираем `stateUpdates` поле — больше не нужно (writes immediate):

```php
final class NodeExecutionResult
{
    public function __construct(
        public readonly string $sourceHandle,
        public readonly array $errorMeta = [],
    ) {}
}
```

## Write Lifecycle

### Single Write Operation

```
Handler executes:
  1. Optionally read state through reader
  2. Compute value
  3. Call writer.write(path, value)
       ├── Path resolution (which storage)
       ├── Validation (reserved keys, structural conflicts)
       ├── Storage operation:
       │     ├── Contact: UPDATE Contact model (column or attributes JSONB)
       │     │   In its own DB transaction (BEGIN-UPDATE-COMMIT)
       │     └── Session: in-memory mutation, persist at end of node
       └── Optional: log to history if flag enabled
  4. Return value or continue handler logic
```

### Per-Node Lifecycle (Engine perspective)

```
Engine executes node:
  1. Acquire context (reader, writer, history logger)
  2. Call handler.execute(context)
  3. Handler performs writes through writer (each immediate)
  4. Handler returns NodeExecutionResult { sourceHandle, errorMeta }
  5. Engine resolves next node by edge lookup
  6. Engine UPDATE flow_sessions SET state = ?, current_node = ?, version = version + 1
     WHERE id = ? AND version = ?
  7. If 0 rows affected (optimistic lock conflict):
       ├── Reload session
       ├── Retry execution (max 3 attempts)
       └── On exhaustion: session failed
  8. If success: continue to next node
```

### Contact Write Atomicity

Каждая Contact write — отдельная транзакция:

```sql
BEGIN;
UPDATE contacts SET attributes = jsonb_set(attributes, '{first_name}', '"Иван"')
  WHERE id = ? AND tenant_id = ?;
COMMIT;
```

Если в одной ноде несколько Contact writes — несколько последовательных мини-транзакций. Между ними возможен partial state при crash, но retry повторит все writes (idempotent через SET).

### Session Save

Session — одна row в `flow_sessions`. Update атомарен. Включает все JSONB изменения сделанные в течение ноды (`state.flow.*`, `state.rag.*`, `state.call.*`, `state.system.*`):

```sql
UPDATE flow_sessions
SET state = ?, current_node_id = ?, version = version + 1, updated_at = now()
WHERE id = ? AND version = ?;
```

Optimistic lock через `version`. Если 0 rows — engine retries.

## Optimistic Lock Retry

### Mechanism

Engine catches version conflict, перечитывает session, повторяет node execution.

```php
final class FlowEngine
{
    private const MAX_RETRIES = 3;
    private const RETRY_DELAY_MS = 100;

    public function executeNode(FlowSession $session, NodeHandler $handler): NodeExecutionResult
    {
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                return $this->tryExecute($session, $handler);
            } catch (OptimisticLockConflictException $e) {
                if ($attempt === self::MAX_RETRIES) {
                    throw new SessionFailureException(
                        "Optimistic lock failed after {$attempt} retries", 0, $e
                    );
                }
                $session = $this->sessionRepo->reload($session->id);
                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }
    }

    private function tryExecute(FlowSession $session, NodeHandler $handler): NodeExecutionResult
    {
        $context = $this->buildContext($session);
        $result = $handler->execute($context);
        // Writer уже применил Contact и in-memory session changes immediately
        $this->saveSession($session);  // throws on version conflict
        return $result;
    }
}
```

### Idempotency Contract for Handlers

Все handlers обязаны быть safe to retry at single-execution level:

| Handler | Idempotency mechanism |
|---------|----------------------|
| `assign` | Write same value twice = same final state. Inherently idempotent |
| `branch` | Pure read, no side effects |
| `delay` | Schedule job — duplicate scheduling safe (delayed job checks current_node_id) |
| `input` | No side effects до получения сообщения. Validation через current_node guard |
| `send_message` | Known limitation V1: дубль возможен при retry. См. ADR Message Routing |
| `call` | Idempotency-Key передаётся transport. HTTP server-side dedup защищает (если поддерживает) |
| `emit_event` | Append-only event log — дубли = два records, downstream handles |
| `subflow` | Child create защищается через UNIQUE INDEX (parent_session_id, parent_resume_node_id) |
| `rag_query` | Provider query — дубль consumes tokens но семантически OK |
| `end` | Terminate — повтор noop |

### Why Engine, Not Handler

Handler уже сложен (validation, expression eval, side effects). Версионирование — engine concern. Уносим из handler.

Handler контракт остаётся простым: получил context, выполнил logic, вернул result. Engine оборачивает retry logic вокруг.

## Module Reads Without Caching

`module.*` reads через DataAccessor — каждый раз новый вызов. Нет per-execution cache.

```php
// In ScopedStateReader:
private function readModule(string $path): mixed
{
    // path = 'module.hr.department'
    [, $moduleName, ...$rest] = explode('.', $path);

    $accessor = $this->accessorRegistry->get($moduleName);
    return $accessor->get(implode('.', $rest), $this->context);
}
```

Каждый `read('module.hr.department')` — accessor call. Если accessor делает DB query — query на каждый read.

### Performance Mitigation Path

Если станет issue:
- DataAccessor implementation добавляет request-scoped cache внутри себя (transparent)
- Engine не управляет — каждый module owns свой caching strategy

В V1 — без cache. Реальные expressions не делают много reads на тот же path.

## History Logging

### Per-Flow Flag

Flow_definition имеет field:

```sql
ALTER TABLE flow_definitions
    ADD COLUMN logging_enabled boolean NOT NULL DEFAULT false;
```

При сохранении flow в UI — пользователь ставит checkbox «Логировать историю изменений». Default OFF (storage conscious).

### What Gets Logged

Когда `logging_enabled = true`:

- Все state changes (Contact + Session) с указанием source ноды
- Subflow lifecycle events (started, returned, with child_session_id)
- Node entry/exit events
- Errors и handler failures

Когда `logging_enabled = false`:
- Только existing `flow_logs` (raw execution log с retention 30d, см. Architecture раздел 8)
- Никаких state snapshots или change events не пишется

### Storage Schema

```sql
CREATE TABLE flow_session_history (
    id ulid PRIMARY KEY,
    tenant_id ulid NOT NULL,
    session_id ulid NOT NULL REFERENCES flow_sessions(id),
    node_id text NOT NULL,
    event_type text NOT NULL,  -- state_change | subflow_started | subflow_returned | node_entered | node_failed
    path text,                  -- для state_change: 'contact.first_name', 'flow.attempts'
    old_value jsonb,
    new_value jsonb,
    metadata jsonb,             -- subflow info, error details, и т.д.
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_history_session ON flow_session_history (session_id, created_at);
CREATE INDEX idx_history_tenant_event ON flow_session_history (tenant_id, event_type, created_at);
```

### History Retention

В V1 — без автоматической retention. История накапливается forever.

V1.x — добавим retention policy per tenant (например, 90 дней default, configurable).

### HistoryLoggerInterface

```php
namespace Fapost\Foundation\Contracts\Flow\History;

interface HistoryLoggerInterface
{
    public function logStateChange(
        FlowSessionInterface $session,
        string $nodeId,
        string $path,
        mixed $oldValue,
        mixed $newValue,
    ): void;

    public function logSubflowStarted(
        FlowSessionInterface $parentSession,
        string $nodeId,
        string $childSessionId,
    ): void;

    public function logSubflowReturned(
        FlowSessionInterface $parentSession,
        string $nodeId,
        string $childSessionId,
        string $outcome,  // success | cancelled | failed
    ): void;

    public function logNodeEntered(FlowSessionInterface $session, string $nodeId): void;

    public function logNodeFailed(
        FlowSessionInterface $session,
        string $nodeId,
        string $errorMessage,
        array $errorMeta,
    ): void;
}
```

### NoOp Logger

Когда `logging_enabled = false` — Engine инжектирует `NoOpHistoryLogger` который ничего не делает. Handlers вызывают methods как обычно, calls absorbed.

Это упрощает handler code — не нужно проверять флаг.

```php
final class NoOpHistoryLogger implements HistoryLoggerInterface
{
    public function logStateChange(...): void { /* noop */ }
    public function logSubflowStarted(...): void { /* noop */ }
    // ...
}
```

### Where Calls Happen

- **Engine** — logNodeEntered, logNodeFailed
- **ScopedStateWriter** — logStateChange (after each write operation, automatic)
- **Subflow handler** — logSubflowStarted при создании child
- **End handler** (с parent_session_id) — logSubflowReturned при resume parent

Handler authors не пишут код для логирования — он automatic через writer и engine.

## Parent / Child Session History

### Independent Logging

Каждая session логирует **сама себя** на основе **своего** `flow_definition.logging_enabled`.

```
Parent flow (logging = ON)
  └── Subflow вызов
        ↓
        Child flow (logging = OFF)
              └── Внутренние ноды НЕ пишут в history (NoOpLogger)

Parent история содержит:
  - Все ноды parent
  - subflow_started event с child_session_id
  - subflow_returned event с outcome

Child история — пустая (logging выключен)
```

### Navigation From Parent

В parent history есть события:

```sql
SELECT * FROM flow_session_history
WHERE session_id = '<parent_id>'
ORDER BY created_at;

-- Returns:
-- node_entered: greet
-- state_change: contact.greeting_done = true
-- node_entered: subflow_collect_data
-- subflow_started: child_session_id = 'X'  ← навигация к child history
-- subflow_returned: child_session_id = 'X', outcome = success
-- node_entered: thank_you
-- ...
```

Если хочешь увидеть что было внутри child:

```sql
SELECT * FROM flow_session_history
WHERE session_id = '<X>'
ORDER BY created_at;
```

Связка через `child_session_id` в metadata события.

### Recursive Trace

Для построения полного дерева вызовов parent → child → grandchild:

```sql
WITH RECURSIVE call_tree AS (
    SELECT id, parent_session_id, flow_id, started_at, 0 as depth
    FROM flow_sessions
    WHERE id = '<root_session_id>'

    UNION ALL

    SELECT s.id, s.parent_session_id, s.flow_id, s.started_at, ct.depth + 1
    FROM flow_sessions s
    JOIN call_tree ct ON s.parent_session_id = ct.id
)
SELECT * FROM call_tree;
```

`flow_sessions.parent_session_id` уже есть (Nodes V1 раздел 4.8). Индекс рекомендуется:

```sql
CREATE INDEX idx_sessions_parent ON flow_sessions (parent_session_id)
    WHERE parent_session_id IS NOT NULL;
```

## Failure Modes

### Crash mid-Contact-write

**Scenario:** assign делает 3 Contact writes. После 1-го crash worker.

**Behavior:**
- 1-я Contact write committed
- 2-я и 3-я не выполнены
- Session не updated (она save'ится в конце ноды)
- Job retry → новый worker → execute ноду снова → 1-я write повторяется (idempotent — same value), 2-я и 3-я применяются

**Outcome:** consistent.

### Crash mid-Session-save

**Scenario:** Contact writes done. Session UPDATE failed (DB connection drop).

**Behavior:**
- Contact уже изменён
- Session current_node всё ещё на этой ноде
- Job retry → execute ноду снова → Contact writes повторяются (idempotent), session save retries → success

**Outcome:** consistent через retry.

### Optimistic lock conflict

**Scenario:** Worker A executing node X. Worker B (parallel — possible после lock heartbeat fail) тоже executing. Worker A коммитит first, Worker B getting 0 rows affected.

**Behavior:**
- Worker B — engine catches OptimisticLockConflictException
- Reload session (Worker A's changes применены)
- Retry node execution (handler idempotent)
- Re-evaluate, possibly write same values

**Outcome:** Worker A's changes preserved, Worker B's retry либо applies same values, либо переходит в новое состояние session (e.g., session уже в waiting_input — handler exits gracefully).

### History logger failure

**Scenario:** logging_enabled=true, но INSERT в flow_session_history fails (DB issue).

**Behavior:**
- В V1: history failure НЕ должен ломать handler execution
- Logger catches own exceptions, logs warning, continues
- State change применён к storage, history record потерян

**Reasoning:** history — secondary concern. Не приоритетнее основной operation. Loss of history record acceptable, loss of state change — нет.

В V1.x можно добавить retry queue для history writes если станет важно.

## V1 Scope

### В V1

- `ScopedStateWriterInterface` + `ScopedStateReaderInterface` в foundation
- `ContactWriter`, `SessionStateWriter` implementations (one facade)
- Independent transactions для Contact и Session writes
- Engine retry on optimistic lock conflict (max 3 attempts)
- Module reads без cache (each read = accessor call)
- `flow_definitions.logging_enabled` field + UI checkbox
- `flow_session_history` table + indexes
- `HistoryLoggerInterface` + `DefaultHistoryLogger` + `NoOpHistoryLogger`
- Auto-instrumentation: writer logs state changes, engine logs node lifecycle, subflow handler logs subflow events
- `flow_sessions.parent_session_id` index для recursive queries

### Не в V1

- Pending state cache / batch flush (отказались)
- Joint Contact+Session transaction (отказались)
- Module reads caching (по необходимости)
- History retention policy (V1.x)
- History retry queue при failure (V1.x)
- Per-node logging override (V1.x — флаг только per-flow)
- History UI viewer в Filament (V1.x — пока только raw SQL queries)

## Test Strategy

### Unit Tests

- `ContactWriter`: column writes, JSONB writes, structural conflict detection, reserved keys rejection
- `SessionStateWriter`: namespace routing, JSONB path operations
- `ScopedStateReader`: path resolution через все namespaces, null handling
- `HistoryLogger`: state change logging, subflow events, NoOp behavior

### Integration Tests

- Immediate write semantics: assign → re-read same path → returns new value
- Independent transactions: simulate Contact write success + Session save failure → verify retry repairs state
- Optimistic lock retry: simulate concurrent UPDATE → engine retries → resolves
- History logging: enable flag, run flow, verify all expected events in history table
- Subflow logging: parent + child both with logging enabled, verify navigation events
- NoOp logger: disable flag, verify no inserts to history table
- Module reads: simulate accessor with side effect counter, verify each read = call

### Failure Mode Tests

- Crash between Contact writes: verify retry recovers
- Crash mid-session-save: verify session not updated, retry rebuilds
- History DB failure: verify state change still applied, warning logged

### Acceptance Criteria

- [ ] All unit tests pass
- [ ] All integration tests pass
- [ ] Manual test: assign 3 полей в одной ноде → crash mid-execution → retry → final state correct
- [ ] Manual test: enable history logging → execute flow with subflow → verify both parent and child histories с events
- [ ] Manual test: disable history → verify zero rows in flow_session_history
- [ ] Performance test: flow с 50 nodes, logging enabled → measure history insert overhead

## Consequences

### Positive

- Простая mental model: writes immediate
- Меньше кода (нет pending cache, batch flush)
- Crash recovery естественная через retry
- History как opt-in — пользователь выбирает trade-off cost/benefit
- Parent/child independence — flexible authoring

### Negative

- Partial state при crash mid-multi-write (acceptable через retry)
- History retention forever в V1 — потенциальный storage bloat для активных tenants
- Module reads без cache — потенциальные performance hits для heavy expressions
- History logger failure тихий — может скрыть issues

### Neutral

- NoOpHistoryLogger в коде — overhead negligible (method calls absorbed)
- Auto-instrumentation в writer — handler authors не контролируют что логируется

## References

- `docs/reference/specs/flow-engine/` — спецификация Flow Engine V1 (раздел 1 state model, раздел 3 NodeHandlerInterface)
- `docs/platform/architecture/adr/09-message-routing-concurrency.md` — concurrency context, idempotency contract
- `docs/platform/architecture/adr/08-expression-language.md` — `ExpressionContext` использует `ScopedStateReader`
- Platform Architecture v2.2 — раздел 5 (concurrency), раздел 8 (logging)

---

**Document final. Implementation ready.**

---

## Amendment · Brownfield Reconciliation (April 2026)

После brownfield audit (см. `docs/archive/platform/plans/flow-engine/brownfield-audit.md`) часть положений этого ADR переопределена решениями synthesis (см. `docs/archive/platform/plans/flow-engine/synthesis.md`):

- **D-3 · Write модель.** Session-state мутации остаются через `NodeExecutionResult.stateChanges` (existing pattern, pure handlers). ADR-овский `ScopedStateWriter` для session **не вводится** в foundation. Только `ContactWriter` для `contact.*` мутаций (replaces existing `effects[]`).
- **D-4 · State primitives.** Существующие `WriteContext`/`NamespaceWritePolicy`/`NamespaceResolverRegistry`/`StateReader`/`StateWriter` (Core) сохраняются — они богаче ADR-овской модели. Foundation thin contracts (`ScopedStateReaderInterface`, `ContactWriterInterface`) — adapter поверх.
- **D-5 · `effects[]` deprecation.** Soft (silent ignore + deprecation logs); engine продолжает обрабатывать legacy `effects[]` пока handlers мигрируются (Phase B-2).
- **D-6 · HistoryLogger.** **НЕ** инжектится в `NodeExecutionContext`. Engine instruments centrally (state_change events from result.stateChanges + ContactWriter notifications + node_entered/failed lifecycle).
- **D-9 · Migrations.** Existing schema kept; новое — additive (новые колонки `expression_engine`/`logging_enabled` на `flow_definitions`, `parent_session_id` etc на `flow_sessions`, новые таблицы `flow_callgraph_edges` + `flow_session_history`).

Финальный контракт описан в `docs/site/extending/flow-nodes/handler-contract.mdx` (the v1.3 spec it replaced was removed in 2026-10).

---

## Amendment · D-4 superseded (September 2026)

D-4 is superseded by `.ai/knowledge/adr/0002-retire-core-state-primitives.md`. The adapter it
planned was never built: `ScopedStateReader` reads state directly, and session and contact
writes go through `FlowSessionPersister` and `ContactWriter`. The Core primitives it kept —
`StateReader`, `StateWriter`, `NamespaceResolverRegistry` and its resolvers, `FlowState`,
`StatePath`, `WriteContext`, `NamespaceWritePolicy` and the local `StateNamespace` enum — were
unused and have been deleted. The rest of this ADR, including D-3, D-5 and D-6, stands.

---

## Связано с

- [[01-state-model]] — state model flow engine
- [[06-call]] — call нода использует state writer
- [[05-assign]] — assign нода пишет в state
- [[README]] — обзор flow engine
- [[specs/flow-engine/01-state-model]] — state model
- [[specs/flow-engine/05-group-storage]] — group storage
- [[diagrams/02-flow-engine-loop]] — диаграмма execution loop
