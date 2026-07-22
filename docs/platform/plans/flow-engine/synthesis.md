# 13 · Synthesis — выбор гибридного подхода

**Дата:** Апрель 2026
**Цель:** для каждого спорного места между существующим кодом и ADR — выбрать конкретное решение по критерию «проще в реализации + легко масштабируемо». Использовать сильные стороны обоих.

> **Контекст:** см. [brownfield-audit.md](brownfield-audit.md) для полного inventory и conflict matrix. Этот документ — **только решения**, без повторного описания существующего кода.

---

## 1. Принципы выбора

> **Update (Апрель 2026):** проект ещё в глубокой разработке, кода в staged/prod нет — только локальное. Поэтому:
> - **Никакой backward compatibility** не требуется
> - Type renames, schema changes — выполняются прямо, без deprecated period
> - Tests обновляются вместе с handlers в одном изменении
> - `effects[]` удаляется hard-cut (а не soft через transition)
> - Старые типы (`condition`/`set_attribute`/`webhook`) удаляются после rename, не сохраняются
> - Контракты переписываются под финальную форму без legacy-полей

1. **Финальная форма с первого подхода.** Refactor в одной волне, не поэтапная миграция.
2. **Где существующее богаче ADR — оставляем существующее** (state primitives, NamespaceResolver/Policy/WriteContext).
3. **Где ADR закрывает реальный пробел — добавляем по ADR** (locks, commands, typing, expression engine, subflow, history).
4. **Foundation contracts — public API.** Thin contracts; rich implementation в Core.
5. **Pure handlers — главное преимущество** (testability), сохраняется через stateChanges-array model.

---

## 2. Решения по конфликтам

### D-1 · NodeHandlerInterface signature

**Существующее:**
```php
interface NodeHandlerInterface {
    public function type(): string;          // non-static
    public function version(): int;
    public function supportedVersions(): array;
    public function label(): string;          // builder UI
    public function category(): string;       // builder UI
    public function configSchema(): array;    // builder validation
    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult;
}
```

**ADR требовал:** `static type()`, без UI методов, `execute(context)` без `nodeConfig`/`state`.

**Решение: оставить существующее.**

- `type()` non-static — соответствует existing handlers + `AbstractVersionedHandler`. ADR-овский static не даёт реальных преимуществ (registry уже работает корректно).
- `label()`/`category()`/`configSchema()` — полезные builder-side метаданные, ADR их просто не упоминал, не запрещал. Оставляем.
- `execute(nodeConfig, state, context)` — current signature. `nodeConfig` и `state` — convenient: handler получает immutable snapshot и может `data_get()` напрямую, не дёргая reader. Остаётся.
- Context расширяется новыми сервисами (см. D-2) — но через **дополнительные** опциональные поля, не через смену сигнатуры.

**Override ADR:** В [03-node-handler-interface.md](03-node-handler-interface.md) переписать §3.1 под существующее, явно отметить что ADR rationale здесь не применяется к brownfield.

---

### D-2 · NodeExecutionContext

**Существующее:** string IDs (`tenantId`/`contactId`/`sessionId`/`nodeId`), `idempotencyKey`, `platform`, `resolvedLanguage`, `incoming: ?IncomingMessage`.

**ADR требовал:** объекты + `ScopedStateReader` + `ScopedStateWriter` + `ExpressionEngine` + `HistoryLogger` + `Definition` + `Contact` + `Session` + `Tenant`.

**Решение: extend, не replace.**

Финальный context:

```php
final readonly class NodeExecutionContext {
    public function __construct(
        // === Existing (kept) ===
        public string $tenantId,
        public string $contactId,
        public string $sessionId,
        public string $nodeId,
        public string $idempotencyKey,
        public string $platform,
        public string $resolvedLanguage = 'en',
        public ?IncomingMessage $incoming = null,

        // === Added by synthesis ===
        public ?ScopedStateReaderInterface $stateReader = null,
        public ?ContactWriterInterface $contactWriter = null,
        public ?ExpressionEngineInterface $expressionEngine = null,
    ) {}
}
```

**Что НЕ добавляем:**
- `ScopedStateWriter` для session state — handler возвращает `stateChanges` через Result, engine применяет (см. D-3). Writer был бы дублированием канала.
- `HistoryLogger` — engine автоинструментирует через `stateChanges` + `ContactWriter` + node lifecycle. Handler не вызывает logger вручную (см. D-6).
- Полные доменные объекты `Contact`/`Session`/`Definition`/`Tenant` — только если handler в самом деле должен с ними работать. Пока handlers работают со string IDs + state — не усложняем. Доступ к доменным объектам — через `$stateReader` или domain services injected в handler constructor.

**Backward compatibility:** новые поля nullable с default `null`. Existing handlers не сломаются. Engine начинает заполнять non-null постепенно.

---

### D-3 · NodeExecutionResult — write модель

**Существующее:** Result с `status` enum + `stateChanges[]` (path → value) + `effects[]` (cross-domain side effects) + `logResolved` + `metadata` + `errorMessage`. Handler — pure function, engine применяет.

**ADR требовал:** Result минимальный (`sourceHandle` + `errorMeta`). Handler пишет immediately через `ScopedStateWriter`. Engine просто резолвит next node.

**Решение: оставить stateChanges-модель для session state, мигрировать `effects[]` в `ContactWriter`.**

Финальный Result:

```php
final readonly class NodeExecutionResult {
    public function __construct(
        public NodeExecutionStatus $status,
        public ?string $sourceHandle = null,
        public array $stateChanges = [],     // session state delta (path => value)
        public array $logResolved = [],
        public array $metadata = [],
        public ?string $errorMessage = null,
        // effects[] DEPRECATED — handler instead calls $context->contactWriter->write(...)
    ) {}
}
```

**Rationale:**
- Pure handlers (testable без mocking writer) — большое преимущество в реализации.
- Atomic transaction в engine: один UPDATE flow_sessions с новым state + version bump.
- Read-after-write в одной ноде через `stateChanges` редко нужно (за все 6 существующих handlers — нет ни одного case). Если понадобится — handler делает локальное вычисление, потом возвращает финальный delta.
- `effects[]` миграция в `ContactWriter`: handler делает `$context->contactWriter->write('contact.first_name', 'Иван')` — это immediate write в Contact (отдельная транзакция, как в ADR). Engine не оборачивает.

**Override ADR:** в [03-node-handler-interface.md](03-node-handler-interface.md) переписать §3.3 и §3.4 под этот гибрид. История auto-instrumentation — engine читает `stateChanges` + слушает `ContactWriter` (который сам пишет в history если flag enabled).

---

### D-4 · State infrastructure: foundation facade vs Core primitives

**Существующее (Core):** `StateReader`/`StateWriter` (которые работают с `FlowState` + `NamespaceResolverRegistry` + `WriteContext` + `NamespaceWritePolicy`). Богаче ADR.

**ADR требовал:** `ScopedStateReaderInterface` + `ScopedStateWriterInterface` в foundation как основной API.

**Решение: foundation thin facade поверх Core implementation.**

```
foundation:
  ScopedStateReaderInterface   ← public API для handlers/plugins (read only paths)
  ContactWriterInterface       ← public API для contact mutations
                                  (replaces effects[] from existing)

core:
  StateReader (existing)       ← rich implementation: FlowState + NamespaceResolver
  StateWriter (existing)       ← остаётся для engine internal use
  ContactWriter (NEW)          ← реализация ContactWriterInterface,
                                  immediate writes в Contact модель,
                                  pushes events в HistoryLogger
  ScopedStateReaderAdapter     ← маппит ScopedStateReaderInterface → StateReader
  WriteContext, Policy,        ← остаются как есть
  NamespaceResolverRegistry
```

**Plugins получают** только foundation contracts. **Core handlers** получают то же (через context), плюс могут use Core services через DI если нужны expert примитивы.

**Rationale:** не теряем `WriteContext`/`Policy` (богатый existing layer), но даём чистый public API для handler authors.

---

### D-5 · ContactWriter — замена effects[]

**Существующее:** `NodeExecutionResult.effects[]` array с типами `set_contact_attribute`, `set_contact_language`. Engine `applyEffects()` интерпретирует.

**ADR требовал:** ContactWriter с immediate writes per-операция, BEGIN-UPDATE-COMMIT.

**Решение: ContactWriter inline в handler через context, immediate write per call.**

Existing handler code:
```php
return NodeExecutionResult::executed(
    sourceHandle: 'success',
    effects: [['type' => 'set_contact_attribute', 'key' => 'first_name', 'value' => 'Иван']],
);
```

Новый код после миграции:
```php
$context->contactWriter->write('contact.first_name', 'Иван');
return NodeExecutionResult::executed(sourceHandle: 'success');
```

**Migration semantics:**
- Phase A: добавляем ContactWriter в context (nullable). Handlers продолжают использовать effects[].
- Phase B: переписываем handler-за-handler на ContactWriter. Каждый refactor — отдельный PR с тестами.
- Phase C: после всех handlers мигрированы — engine `applyEffects()` удаляется.

**Rationale:** делает state mutations explicit в handler коде; убирает «магию» effects array; ADR-совместимо для plugins; обратная совместимость через transitional period.

---

### D-6 · HistoryLogger

**Существующее:** нет.

**ADR требовал:** `HistoryLoggerInterface` в context handler'а; auto-instrumentation: `ScopedStateWriter` пишет события автоматически, engine — node lifecycle.

**Решение: NOT в context handler'а; engine инструментирует централизованно.**

Mechanism:
- Engine после `handler.execute()` видит `result.stateChanges` — пишет state_change events в `flow_session_history` per path (если `definition.logging_enabled = true`).
- `ContactWriter` сам пишет state_change events для contact-уровня (он в Core, имеет доступ к history infrastructure).
- Engine логирует `node_entered` / `node_failed` / `subflow_started` / `subflow_returned` сам.
- Handler **никогда** не вызывает logger напрямую.

**Rationale:**
- Handler stays pure (no logger dependency).
- Centralized instrumentation = consistent.
- Простая реализация: один listener в engine + один в ContactWriter.
- Plugins не могут случайно skip логирование (нет доступа к logger).

**Override ADR:** убрать `HistoryLoggerInterface` из NodeExecutionContext. История полностью engine-driven.

---

### D-7 · Existing handlers → V1 nodes mapping

**Существующее:** 6 handlers. **ADR/spec:** 10 nodes (Branch, Assign, Call, EmitEvent, RagQuery, Subflow, End — net-new или расширение).

**Решение: расширять existing через `version: 2`, не вводить новые `type`.**

| Existing type | Расширение в V1 | Mechanism |
|---------------|------------------|-----------|
| `send_message` v1 | `send_message` v2 (dynamic keyboard validation, attempt_id idempotency contract — пока без Redis dedup, channel limits enforce) | version bump |
| `input` v1 | `input` v2 (date с timezone, contact/photo/file types, max_attempts_handle) | version bump |
| `condition` v1 | `condition` v2 (multi-case = ADR's `branch`; v1 — boolean true/false; v2 — массив cases + default) | version bump |
| `delay` v1 | `delay` v2 (если изменения нужны) или v1 OK | TBD |
| `set_attribute` v1 | `assign` v2 (multi-op + literal target validation; type=`set_attribute` mapping в snapshot или новый type=`assign`) | **needs decision — см. ниже** |
| `webhook` v1 | `call` v2 (pluggable transport + success_when + result_mapping; type stays `webhook` или новый `call`) | **needs decision — см. ниже** |

**Net-new (новые `type`):**
- `emit_event`
- `subflow`
- `rag_query`
- `end`

**Открытый вопрос для пользователя:** для `set_attribute → assign` и `webhook → call` — переименовать `type` или оставить старое имя с version 2?
- **Переименовать (новый type):** чище семантически (assign = multi-op, не set_attribute). Старые flows продолжают работать через `type=set_attribute` v1; новые flows используют `type=assign`. UI builder palette показывает новые. Migration не нужна (snapshot immutable).
- **Оставить (version 2):** проще для UI builder (один тип node в palette), но семантически путано.
- **Recommended:** переименовать. ADR Subflow Composition уже зафиксировал что immutable snapshot достаточен — old flows не ломаются.

---

### D-8 · MessageSenderInterface — двойственность

**Существующее:** `MessageSenderInterface` есть и в foundation, и в Core (`Domains/Flow/Contracts/`). Подписи отличаются.

**Решение: foundation = public contract, Core implements.**

- Foundation: `MessageSenderInterface.send(OutboundMessage, string $idempotencyKey): DeliveryResult` (с idempotencyKey добавлен).
- Core `MessageSenderInterface` удаляется. Все callers переключаются на foundation.
- `FlowMessageSender` (Core impl) реализует foundation contract; добавляет приём `idempotencyKey`, в V1 просто прокидывает в провайдер (HTTP — `Idempotency-Key` header). Без Redis SET NX dedup (V1.x).
- `SentMessageResult` / `DeliveryResult` — унифицировать в одно DTO в foundation.

---

### D-9 · State primitives — sql migrations

**Decision: pure additive migrations.** Существующие таблицы не трогаем; новые колонки и таблицы — отдельные миграции:

| Migration | Содержит |
|-----------|----------|
| `add_expression_engine_to_flow_definitions` | `expression_engine` text default `'template'`, `logging_enabled` boolean default `false` |
| `add_subflow_columns_to_flow_sessions` | `parent_session_id` uuid nullable + index, `parent_resume_node_id` text nullable, `end_status` text nullable + check |
| `extend_flow_sessions_status_enum` | DROP + ADD CHECK constraint с расширенным enum: добавить `paused_subflow`, `terminated_by_user`, `expired`, `ended` (alias `completed`?) |
| `create_flow_callgraph_edges` | reverse-index таблица |
| `create_flow_session_history` | history events table с indexes |
| `add_commands_busy_message_to_assistants` | `commands` JSONB default `'[]'`, `busy_message` text nullable |
| `add_timezone_to_tenant_settings` | если timezone хранится в `tenants.settings` JSONB — отдельный column не нужен; иначе add column. Default `'UTC'` |
| `add_expression_engine_to_tenant_settings` | аналогично — JSONB key или column |

---

### D-10 · Validation: soft draft vs strict publish

**Существующее:** `ValidateFlowService` + `PublishFlowService` + `SaveDraftService`. Уже разделены — есть `ValidateFlowServiceTest`/`PublishFlowServiceTest`/`SaveDraftServiceTest`.

**ADR требовал:** soft draft = warnings, strict publish = blocking errors.

**Решение: проверить existing — скорее всего уже работает в этом духе.**

- TODO в Phase A-0 (audit step): прочитать `ValidateFlowService` / `PublishFlowService` / `SaveDraftService` и понять текущую модель. Если `validateForDraft()` / `validateForPublish()` уже есть — добавляем недостающие правила (subflow cycle/depth/cross-assistant, variable path uniqueness, branch unary/binary).
- Если existing — single-mode (всегда blocking), refactor в `ValidationResult{errors, warnings}` + два метода.
- 6.5 spec (variable path uniqueness) — добавляется как `Rule` в существующую систему правил.

---

### D-11 · `flow_logs` vs `flow_session_history` — coexistence

**Decision: keep both, разные назначения.**

- `flow_logs` — operational, всегда, retention 30 дней, partitioned. Это **execution trace** — какая нода была executed, какой sourceHandle, какие state_changes (краткий snapshot для debugging).
- `flow_session_history` — opt-in audit trail, когда `flow_definition.logging_enabled = true`, retention manual (V1 — forever, V1.x — policy). Это **business audit** — все state changes с old/new values для анализа behaviour.

Они не конкурируют. Builder UI checkbox «логировать историю изменений» включает второе.

---

### D-12 · Octane scope (ADR-01)

**Existing:** см. CLAUDE.md — Octane только для webhook ingress, main app PHP-FPM. ExpressionEvaluator cache должен быть **per-request scoped**, не cross-request.

**Decision:** при реализации `ExpressionEngineRegistry` — engines регистрируются in-memory boot-time (singleton OK; immutable). Parsed-expression cache в `TemplateEngine` — **request-scoped**, не сохраняется между requests. Phpat правило: cache static не используется в expression layer.

---

## 3. Что не меняется (ADR принимается as-is)

- **ADR Expression Language** — full pluggable model, V1 ships TemplateEngine. Без brownfield конфликтов (ничего такого не существует).
- **ADR Message Routing & Concurrency Control** — distributed lock + heartbeat + commands + typing — всё net-new. Existing `/reset` мигрируется в Phase D-3.
- **ADR Subflow Composition** — все примитивы net-new (callgraph_edges, parent_session_id, lifecycle service). Validation — добавление правил в existing `ValidateFlowService`.
- **ADR State Writer Semantics** — частично переопределяется через D-3..D-6 (history через engine, не writer; effects→ContactWriter; stateChanges остаются). Immediate writes для `contact.*` через ContactWriter — соответствует ADR. Session writes через result.stateChanges — расхождение с ADR, документируется в final spec patch.

---

## 4. Closed decisions (Q-1..Q-4 answered)

### Q-1 · `set_attribute → assign` и `webhook → call`

**Решение:** **Rename** — новые types `assign` и `call` в palette. Старые `set_attribute` / `webhook` deprecated в UI, но продолжают работать (snapshot immutable). Builder для новых flows предлагает только новые types.

### Q-2 · `condition → branch`

**Решение:** **Rename** в type `branch`. Старый `condition` deprecated. Single-direction миграция: новые flows используют `branch`, существующие flows с `condition` v1 продолжают работать через soft-compat (handler `condition` остаётся зарегистрирован).

### Q-3 · NodeExecutionContext domain objects

**Решение:** **Нет.** Только string IDs + services (`stateReader`, `contactWriter`, `expressionEngine`). Handler injects repositories/services через constructor если нужны полные объекты. Foundation остаётся свободным от Core domain interfaces.

### Q-4 · `effects[]` deprecation

**Решение:** **Soft.** Engine продолжает обрабатывать `effects[]` для legacy handlers, логирует deprecation warning при встрече. После миграции всех 6 handlers (Phase B) — поле удаляется из `NodeExecutionResult`.

### Combined implication для V1 nodes mapping

| Existing | V1 spec name | Migration |
|----------|--------------|-----------|
| `send_message` | `send_message` | version bump v2 (new contracts) |
| `input` | `input` | version bump v2 (extended types, timezone) |
| `condition` | `branch` | **rename** — handler `condition` остаётся для legacy snapshots, новый `branch` в palette |
| `set_attribute` | `assign` | **rename** — handler `set_attribute` остаётся, новый `assign` в palette |
| `webhook` | `call` | **rename** — handler `webhook` остаётся, новый `call` в palette с pluggable transport |
| `delay` | `delay` | minor refactor (если нужно) |

**Net-new types (без legacy):** `emit_event`, `subflow`, `rag_query`, `end`.

**Палитра builder UI после реализации:** 10 типов V1 (`send_message`, `input`, `branch`, `assign`, `call`, `delay`, `emit_event`, `subflow`, `rag_query`, `end`) + 4 deprecated скрыты по умолчанию (`condition`, `set_attribute`, `webhook` — старые версии, плюс при желании отдельно `webhook` v1 если понадобится для совместимости).

---

## 5. Outcome

После согласия по Q-1..Q-4 — план реализации финализируется. Обновляется [implementation-plan.md](implementation-plan.md) под Phase A–E (см. [brownfield-audit.md §4](brownfield-audit.md)) с конкретными файлами и acceptance criteria.

В существующих ADR-документах (`10-state-writer-semantics.md`, `08-expression-language.md`, `09-message-routing-concurrency.md`, `11-subflow-composition.md`) — добавляется amendment-секция «Brownfield reconciliation» в каждый, где зафиксированы local override решения (D-1..D-12).

Альтернативно — отдельный документ `adr-brownfield-migration-strategy.md` который объединяет все амендменты.

---

## Связано с

- [[README]] — flow engine README
- [[implementation-plan]] — план реализации
- [[08-acceptance-criteria]] — критерии приёмки
- [[ROADMAP]] — дорожная карта
