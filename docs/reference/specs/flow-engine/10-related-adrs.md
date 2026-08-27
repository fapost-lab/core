# 10 · Связанные документы и Future ADR

## 10.1 Существующие документы

- `Platform Architecture v2.2` — раздел 3.4 Flow Engine, раздел 5 Concurrency
- `FaPost Plan v2.0` — Phase 2 tasks 09-16
- `ADR-05 Foundation Package` — где живут foundation contracts
- `ADR Handler Versioning Contract` — task 09

## 10.2 Future ADRs

Часть решений из review responses выходит за scope этой спецификации и заслуживает отдельных документов.

### ADR Idempotency Strategy (full Redis dedup) — TBD V1.x

**Scope:** cross-cutting concern всего outbound layer для broadcast / financial use cases.

**Status в V1:** минимальный контракт зафиксирован в ADR Message Routing & Concurrency Control. Поле `idempotencyKey` присутствует в `MessageSenderInterface` и `CallContext`, но Redis-based dedup не реализуется. `attempt_number` в V1 статически = 1.

Полный ADR требуется когда появятся реальные кейсы (broadcast 10k+ контактов, financial calls). Объединит:
- attempt_number tracking с automatic increment
- Redis SET NX conventions, TTL policies (TTL ≥ max queue retention + grace)
- Worker crash mid-send recovery (currently known V1 limitation)
- Retry safety contract for all outbound operations
- attempt_number vs flow_sessions.version разведение

### ADR State Writer Semantics ✓ Accepted

**Status:** Accepted (Апрель 2026). Документ: [`10-state-writer-semantics.md`](../../../platform/architecture/adr/10-state-writer-semantics.md).

**Scope:** state management invariants движка + opt-in history logging.

**Resolution summary:**
- **Immediate writes** через `ScopedStateWriterInterface` фасад (foundation), без pending cache / batch flush
- Routing: `ContactWriter` (independent BEGIN-UPDATE-COMMIT per write) и `SessionStateWriter` (in-memory мутация, persist одним UPDATE в конце ноды)
- Independent transactions Contact ↔ Session (рассинхрон допустим, retry самокорректирует)
- **Engine retry on optimistic lock** (max 3 attempts × 100ms); handler обязан быть idempotent
- Module reads без cache (per-read DataAccessor call)
- **History logging** — opt-in feature через `flow_definitions.logging_enabled` field, новая таблица `flow_session_history`, `HistoryLoggerInterface` с `DefaultHistoryLogger` / `NoOpHistoryLogger`
- Auto-instrumentation: writer логирует state changes, engine — node lifecycle, subflow handler — subflow events
- Parent/child sessions логируют независимо (по своему `logging_enabled`); навигация через `subflow_started` / `subflow_returned` события с `child_session_id`
- `NodeExecutionContext` расширяется: `ContactInterface`, `FlowDefinitionInterface`, `HistoryLoggerInterface`

Implementation tasks — см. [implementation-plan.md](../../../platform/plans/flow-engine/implementation-plan.md), S4-4 (state infrastructure) и новая S4-4a (history logging).

### ADR Subflow Composition ✓ Accepted

**Status:** Accepted (Апрель 2026). Документ: [`11-subflow-composition.md`](../../../platform/architecture/adr/11-subflow-composition.md).

**Scope:** full subflow design в одном месте.

**Resolution summary:**
- **Wait mode only** в V1; fire-and-forget — через `emit_event`
- **V1 без параметров.** Snapshot готов к extension: `input_mapping` / `output_mapping` reserved fields, V1 implementation ignores; V1.1 implementation использует. Координация parent ↔ child через `contact.attributes` (с временными `_tmp_*` префиксами)
- **Same-assistant only.** Cross-assistant subflow refuses при publish; V2
- **Depth ≤ 3, recursion (direct + indirect) запрещена.** Detection через reverse-index `flow_callgraph_edges`, BFS forward + reverse от изменяемого flow, FOR UPDATE на edges при publish для защиты от concurrent races
- **Latest active version always.** Pin specific version (`flow_version: N`) — V1.x по требованию
- **Soft draft / strict publish validation:** save draft возвращает warnings без блокировки; publish refuses при любом нарушении (cycle, depth, cross-assistant, missing reference)
- **Lock chain inheritance:** lock на (tenant, contact, assistant) семантически передаётся parent → child seamlessly, один worker удерживает через всю chain. Heartbeat continues
- **Parent.expires_at extends** при child start (`max(parent.expires_at, child.expires_at + 1h)`); не сжимается обратно после resume
- **Independent histories** parent и child (по своим `logging_enabled`); parent логирует `subflow_started` / `subflow_returned` events с `child_session_id`
- **Cascade refuse** при depth violation у callers — admin migrates affected flows перед publish callee
- **Multiple parallel children — never** (нарушает single-active-execution invariant)

Implementation tasks — см. [implementation-plan.md](../../../platform/plans/flow-engine/implementation-plan.md), S6-4.

### ADR Message Routing & Concurrency Control ✓ Accepted

**Status:** Accepted (Апрель 2026). Документ: [`09-message-routing-concurrency.md`](../../../platform/architecture/adr/09-message-routing-concurrency.md).

**Scope:** concurrency, routing, escape commands, UX during long execution.

**Resolution summary:**
- Distributed lock на `(tenant, contact, assistant)` с TTL 30s покрывает всю active execution от resume до next pause point
- Heartbeat extension каждые 10s, refresh типинга каждые 4s
- `LockAcquisitionPolicy`: 3 backoff retry × 2s, потом drop с user-facing busy notice
- Global commands matched **до** acquire lock — escape для stuck flows
- Built-in `/reset`, `/cancel` (response text overridable per tenant); tenant configurable commands в `assistants.commands` JSONB; module-registered commands — V1.x
- Action types: `terminate_session`, `start_flow`, `send_message`
- 6-шаговый routing pipeline (command match → typing → lock → state check → execution → cleanup)
- ChannelAdapter методы `indicateProcessing` / `stopProcessing` + `TypingIndicatorService` с heartbeat
- `assistant.busy_message` field
- Минимальный idempotency contract: `idempotencyKey` параметр существует в `MessageSenderInterface` и `CallContext`, в V1 не используется для dedup (см. полную ADR Idempotency для V1.x)
- Known V1 limitation: worker crash между send и save может дать дубль исходящего

Implementation tasks — см. [implementation-plan.md](../../../platform/plans/flow-engine/implementation-plan.md), новые S4-7a (concurrency primitives), S5-7 (commands), S5-8 (typing).

Refactoring task: migrate существующий `/reset` под новую command architecture.

### ADR Expression Language ✓ Accepted

**Status:** Accepted (Апрель 2026). Документ: [`08-expression-language.md`](../../../platform/architecture/adr/08-expression-language.md).

**Scope:** expression contract для всех нод.

**Resolution summary:**
- Pluggable strategy через `ExpressionEngineInterface` (foundation)
- `ExpressionEngineRegistry` в core, fail-on-conflict
- Engine выбирается per-tenant через `tenant.settings.expression_engine` (default `'template'`)
- `flow_definitions.expression_engine` — snapshot field, immutable per definition (защищает running sessions от config drift)
- V1 ships только built-in `TemplateEngine` (regex `{{path}}` substitution)
- Symfony EL / Twig / custom — добавляются по business need в V1.x+
- Conditions (Branch) остаются engine-agnostic structured JSON
- Migration expressions между engines — admin responsibility, не platform

Implementation tasks — см. [implementation-plan.md](../../../platform/plans/flow-engine/implementation-plan.md), S4-5.

---

## Связано с

- [[01-octane-ingress-only]] — ADR-01
- [[03-id-strategy-ulid]] — ADR-03
- [[05-foundation-contract-package]] — ADR-05
- [[06-frontend-extension-boundary]] — ADR-06
- [[10-state-writer-semantics]] — ADR state writer
- [[09-message-routing-concurrency]] — ADR concurrency
- [[11-subflow-composition]] — ADR subflow
- [[08-expression-language]] — ADR expression language
