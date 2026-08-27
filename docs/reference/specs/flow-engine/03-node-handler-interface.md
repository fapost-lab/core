# 03 · NodeHandlerInterface & State Writers

> **Версия:** v1.3 (после brownfield reconciliation, см. [synthesis.md](../../../platform/plans/flow-engine/synthesis.md))
>
> Этот документ описывает **финальный** контракт после согласования ADR State Writer Semantics с реальной кодовой базой. Где в ADR были универсальные предложения — здесь фиксируется конкретная гибридная форма (см. D-1..D-6 в synthesis).

## 3.1 NodeHandlerInterface (foundation)

```php
namespace Fapost\Foundation\Contracts;

interface NodeHandlerInterface
{
    /**
     * Node type identifier — 'send_message', 'branch', 'assign', etc.
     * Non-static for compatibility with AbstractVersionedHandler base class
     * which reads class TYPE constant.
     */
    public function type(): string;

    /**
     * Current handler version.
     */
    public function version(): int;

    /**
     * Versions supported by this handler. Default — [version()].
     *
     * @return list<int>
     */
    public function supportedVersions(): array;

    /**
     * Builder UI label (locale-neutral) and category for the palette.
     * Implemented as default in AbstractVersionedHandler.
     */
    public function label(): string;
    public function category(): string;

    /**
     * Builder-side validation hints — required keys, default config payload.
     *
     * @return array<string, mixed>
     */
    public function configSchema(): array;

    /**
     * @param  array<string, mixed>  $nodeConfig  Full node payload from flow definition JSON
     * @param  array<string, mixed>  $state       Namespaced session state (read-only snapshot)
     */
    public function execute(
        array $nodeConfig,
        array $state,
        NodeExecutionContext $context,
    ): NodeExecutionResult;
}
```

> **Reconciliation note (D-1):** существующий контракт сохраняется. Static `type()` из ADR не применяется. UI-методы (`label`, `category`, `configSchema`) остаются — ADR их не запрещал. Сигнатура `execute(nodeConfig, state, context)` тоже сохраняется: snapshot state как параметр удобнее, чем hand-rolled `$reader->read()` для каждого `data_get()`.

## 3.2 NodeExecutionContext (foundation)

```php
final readonly class NodeExecutionContext
{
    public function __construct(
        // === Identifiers ===
        public string $tenantId,
        public string $contactId,
        public string $sessionId,
        public string $nodeId,
        public string $idempotencyKey,
        public string $platform,
        public string $resolvedLanguage = 'en',

        // === Routing input (set on resume) ===
        public ?IncomingMessage $incoming = null,

        // === Reconciled additions (Phase B-1) ===
        public ?ScopedStateReaderInterface $stateReader = null,
        public ?ContactWriterInterface $contactWriter = null,
        public ?ExpressionEngineInterface $expressionEngine = null,
    ) {}
}
```

> **Reconciliation note (D-2, Q-3):** существующие string-ID поля + `incoming` сохраняются. Доменные интерфейсы (`Contact`/`Session`/`Definition`/`Tenant`) **не** добавляются — handler инжектит repositories в constructor если ему нужны полные объекты. Это удерживает foundation свободным от Core domain abstractions.
>
> Три новых nullable поля будут заполняться engine начиная с Phase B-1. Existing handlers, которые их не используют — продолжают работать.

## 3.3 NodeExecutionResult (foundation)

```php
final readonly class NodeExecutionResult
{
    public function __construct(
        public NodeExecutionStatus $status,
        public ?string $sourceHandle = null,

        /** @var array<string, mixed> path => value */
        public array $stateChanges = [],

        /** @var array<string, mixed> values logged to flow_logs.resolved */
        public array $logResolved = [],

        /**
         * @deprecated since Phase B-1 — handlers must use $context->contactWriter instead.
         *             Engine will continue to interpret legacy effects[] until removed in Phase E.
         * @var array<int, array<string, mixed>>
         */
        public array $effects = [],

        public array $metadata = [],
        public ?string $errorMessage = null,
    ) {}

    // ::executed(), ::waiting(), ::delayed(), ::finished(), ::failed()
}
```

`NodeExecutionStatus` enum: `Executed | Waiting | Delayed | Finished | Failed`.

> **Reconciliation note (D-3, Q-4):** контракт **сохраняет** `stateChanges` array как канал session-state мутаций. Это даёт pure handlers (handler — pure function), atomic transaction в engine (один UPDATE flow_sessions с version bump), и упрощает testing.
>
> ADR-овская модель «handler пишет immediately через ScopedStateWriter» не применяется к session state — её заменяет stateChanges-array.
>
> **Только `effects[]` помечен deprecated.** Phase B-2 мигрирует все 6 существующих handlers на `$context->contactWriter->write()` для contact mutations. После завершения Phase B — `effects` поле удаляется.

## 3.4 State infrastructure

### 3.4.1 Foundation thin contracts

`packages/fapost-foundation/src/Flow/Contracts/`:

- `ScopedStateReaderInterface` — `read(string $path): mixed`. Resolves contact / flow / system / rag / call / module через namespace prefix. Null on missing.
- `ContactWriterInterface` — `write(string $path, mixed $value): void`. Path всегда начинается с `contact.`. Validates reserved keys, structural conflicts, depth limits. Каждый write — независимая BEGIN-UPDATE-COMMIT транзакция.

> **Reconciliation note (D-4):** `ScopedStateWriter` для session-state в foundation **не вводится** — handler возвращает session мутации через `result.stateChanges`. ContactWriter вводится для замены `effects[]` (Phase B-2 migration).

### 3.4.2 Core implementation (existing — kept)

`app/Domains/Flow/State/`:

- `FlowState` — runtime state container per execution.
- `StatePath` — namespace + path value object.
- `StateNamespace` — enum (System / Flow / Rag / Module — расширим до Call в Phase B).
- `StateReader` / `StateWriter` — Core implementations используя `NamespaceResolverRegistry`.
- `WriteContext` (engine / node / builder / accessor — кто пишет) — origin tracking.
- `NamespaceWritePolicy` (EngineOnly / NodeRestricted / Writable / AccessorOnly) — write authorization rules.
- `Resolvers/NamespaceResolverRegistry` + per-namespace resolvers.

Core implementations **богаче** ADR-предложений и сохраняются. Foundation contracts — adapter поверх:

- `ScopedStateReaderInterface` impl будет thin adapter поверх Core `StateReader` (Phase A-4 follow-up или встроится в Phase B-1 при wiring engine).
- `ContactWriterInterface` impl — отдельный класс в Core, который пишет в Contact модель + emits history events (Phase B-1).

### 3.4.3 Engine flow

```
Engine executes node:
  1. Build NodeExecutionContext (with stateReader, contactWriter, expressionEngine)
  2. result = handler.execute(nodeConfig, state, context)
       — handler возвращает stateChanges (session mutations)
       — handler может call $context->contactWriter->write() для contact mutations
         (each call = own transaction, immediate)
       — engine BC-window: legacy handlers могут ещё возвращать effects[]
  3. BEGIN TRANSACTION
       Apply result.stateChanges to FlowSession.state JSON
       Apply legacy effects[] (deprecated path)
       UPDATE flow_sessions SET state=?, current_node=?, version=version+1
         WHERE id=? AND version=?
       Write flow_logs row with state_changes/resolved/error snapshot
       Auto-instrument flow_session_history if logging_enabled (state_change events
         from stateChanges, node_entered/node_failed from engine itself)
       Emit analytics event if applicable
     COMMIT
  4. If 0 rows affected → OptimisticLockConflictException → reload + retry (max 3)
  5. Resolve next node by edge lookup using result.sourceHandle
  6. Continue or break (status decides)
```

### 3.4.4 Read-after-write semantics

В рамках одной execute():

- **Session state** (`flow.*`, `system.*`, `rag.*`, `call.*`): handler делает локальные вычисления, возвращает финальный delta в `stateChanges`. Read-after-write нужен редко; если handler пишет два раза в один path внутри ноды — он сам управляет последовательностью локально.
- **Contact state** (`contact.*`): первый `contactWriter->write()` коммитит в БД; повторный read через `stateReader` возвращает свежий committed value (Contact модель refreshes).

Это проще и предсказуемее, чем pending-cache модель из изначального ADR.

## 3.5 History instrumentation (engine-driven)

> **Reconciliation note (D-6):** `HistoryLoggerInterface` НЕ инжектится в handler context. Auto-instrumentation полностью centralized:

- **Engine** при entry в ноду: `node_entered` event.
- **Engine** при handler exception / failed status: `node_failed` event.
- **Engine** после applying `stateChanges`: `state_change` events per path (с old/new value snapshot из state до execution и result.stateChanges).
- **ContactWriter** после успешного write: `state_change` event для contact-уровня (с old/new из Contact модели).
- **SubflowHandler** при создании child: `subflow_started`.
- **EndHandler** при resume parent: `subflow_returned` с outcome.

Все они слушают `flow_definition.logging_enabled` — если `false`, instrumentation no-op.

Handler не имеет прямого доступа к logger — невозможно случайно скипнуть.

## 3.6 Registration

Built-in handlers регистрируются в `FlowServiceProvider::boot()` через существующий `NodeHandlerRegistryInterface`:

```php
$registry->register(new SendMessageNodeHandler(...));
$registry->register(new InputNodeHandler(...));
$registry->register(new ConditionNodeHandler(...));        // legacy v1, kept
$registry->register(new BranchNodeHandler(...));            // new (Phase B-2)
$registry->register(new SetAttributeNodeHandler(...));     // legacy v1, kept
$registry->register(new AssignNodeHandler(...));            // new (Phase B-2)
$registry->register(new WebhookNodeHandler(...));          // legacy v1, kept
$registry->register(new CallNodeHandler(...));              // new (Phase B-2)
$registry->register(new DelayNodeHandler(...));
// ... + Phase C: EmitEventNodeHandler, RagQueryNodeHandler, EndNodeHandler, SubflowNodeHandler
```

> **Reconciliation note (Q-1, Q-2):** старые types (`condition`, `set_attribute`, `webhook`) **остаются зарегистрированными** для legacy snapshots — running flow_definitions с `type=condition` v1 продолжают работать. Новые types (`branch`, `assign`, `call`) добавляются параллельно. UI builder palette показывает только новые; старые скрыты.

`FlowServiceProvider::boot()` также регистрирует `ExpressionEngineRegistry` с built-in `TemplateEngine` (id `'template'`).

Все registries — fail-on-conflict. Freeze в `app->booted()` для non-testing environments. Существующая инфраструктура.

## 3.7 What's already done (Phase A progress)

- ✓ Foundation contracts: `ScopedStateReaderInterface`, `ContactWriterInterface`, `ExpressionEngineInterface`, `ExpressionContext`, `ExpressionEngineNotFoundException`, `ExpressionEvaluationException`, `ExpressionSyntaxException`
- ✓ Migrations: `flow_definitions.expression_engine`/`logging_enabled`, `flow_sessions.parent_session_id`/`parent_resume_node_id`/`end_status`, status enum extension, `flow_callgraph_edges`, `flow_session_history`, `assistants.commands`/`busy_message`
- ✓ `ExpressionEngineRegistry` (Core) + `TemplateEngine` (Core impl) + tests + wired in FlowServiceProvider

Pending в Phase A:

- HistoryLogger (Default + NoOp) + engine instrumentation
- SessionLockManager + heartbeat + acquisition policy
- ChannelInterface typing extension + Telegram impl
- CallTransport layer (Http + Handler transports)
- ActionHandlerRegistry + ActionHandlerInterface
- BuiltinCommandsRegistry + tenant commands UI
- ContactWriter Core implementation (when Phase B-1 wires it into context)

---

## Связано с

- [[02-common-concepts]] — общие концепции
- [[05-foundation-contract-package]] — NodeHandlerInterface в Foundation
- [[nodes/README]] — каталог нод
- [[07-versioning]] — версионирование handlers
- [[diagrams/02-flow-engine-loop]] — диаграмма execution loop
- [[06-flow-engine]] — flow engine архитектура
