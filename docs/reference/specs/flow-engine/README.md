# Flow Engine — Core Nodes V1

**Версия документа:** v1.3 (после brownfield reconciliation, Май 2026)
**Статус реализации:** см. [`../../../platform/TASKS.md`](../../../platform/TASKS.md)
**Контекст:** FAPost Phase 2 — Flow Engine
**Источник snapshot:** `./_snapshot-v1.0.md` (v1.0)

> Документ разбит на отдельные файлы по логическим единицам реализации. Все 4 ADR приняты. Brownfield audit + synthesis закрыли расхождения между ADR и фактической кодовой базой. Плановые документы живут в `../../../platform/plans/flow-engine/`.

## Specs (порядок чтения)

| Файл | Содержимое |
|------|-----------|
| [00-overview.md](00-overview.md) | Обзор, принципы V1 |
| [01-state-model.md](01-state-model.md) | Namespaces, contact addressing, reserved keys |
| [02-common-concepts.md](02-common-concepts.md) | VariableDef, Expression, output handles, JSON snapshot |
| [03-node-handler-interface.md](03-node-handler-interface.md) | NodeHandlerInterface, Context, Result, ScopedStateReader/ContactWriter, History instrumentation **(финальная reconciled форма v1.3)** |
| [04-call-transport-layer.md](04-call-transport-layer.md) | CallTransport, ActionHandler, fail-on-conflict registries |
| [05-group-storage.md](05-group-storage.md) | Groups в attributes, discovery, GIN |
| [06-validation.md](06-validation.md) | 6 уровней валидации flow_definition + draft/publish дифференциация |
| [07-versioning.md](07-versioning.md) | Handler versioning, deprecated removal |
| [08-acceptance-criteria.md](08-acceptance-criteria.md) | Критерии готовности V1 |
| [09-out-of-scope-and-open-questions.md](09-out-of-scope-and-open-questions.md) | V1.x backlog (все open questions закрыты) |
| [10-related-adrs.md](10-related-adrs.md) | Каталог принятых ADR + future deliverables |

## Reconciliation & Plan

| Файл | Содержимое |
|------|-----------|
| [implementation-plan.md](../../../platform/plans/flow-engine/implementation-plan.md) | **Финальный план Phase A–E** с зафиксированным прогрессом и решениями D-1..D-12 / Q-1..Q-4 |
| [brownfield-audit.md](../../../platform/plans/flow-engine/brownfield-audit.md) | Inventory существующего кода + conflict matrix vs ADR |
| [synthesis.md](../../../platform/plans/flow-engine/synthesis.md) | Финальные решения D-1..D-12 + closed Q-1..Q-4 |

## Nodes

| Файл | V1 type | Legacy type kept |
|------|---------|------------------|
| [nodes/01-send-message.md](nodes/01-send-message.md) | `send_message` | — |
| [nodes/02-input.md](nodes/02-input.md) | `input` | — |
| [nodes/03-branch.md](nodes/03-branch.md) | `branch` (Q-2) | `condition` (legacy snapshots) |
| [nodes/04-delay.md](nodes/04-delay.md) | `delay` | — |
| [nodes/05-assign.md](nodes/05-assign.md) | `assign` (Q-1) | `set_attribute` (legacy snapshots) |
| [nodes/06-call.md](nodes/06-call.md) | `call` (Q-1) | `webhook` (legacy snapshots) |
| [nodes/07-emit-event.md](nodes/07-emit-event.md) | `emit_event` | — |
| [nodes/08-subflow.md](nodes/08-subflow.md) | `subflow` | — |
| [nodes/09-rag-query.md](nodes/09-rag-query.md) | `rag_query` | — |
| [nodes/10-end.md](nodes/10-end.md) | `end` | — |
| [nodes/11-loop.md](nodes/11-loop.md) | `loop` + `loop_end` (addendum v2.0, июнь 2026 — приведён к текущим контрактам) | — |

Node usage statistics (вынесено из loop-addendum) — отдельный draft: [`../node-usage-statistics.md`](node-usage-statistics.md).

## Accepted ADRs

| # | ADR | Date | File |
|---|-----|------|------|
| B-1 | Expression Language Pluggability | Апр 2026 | [`08-expression-language.md`](../../../platform/architecture/adr/08-expression-language.md) |
| B-2 | Message Routing & Concurrency Control | Апр 2026 | [`09-message-routing-concurrency.md`](../../../platform/architecture/adr/09-message-routing-concurrency.md) |
| B-3 | State Writer Semantics | Апр 2026 | [`10-state-writer-semantics.md`](../../../platform/architecture/adr/10-state-writer-semantics.md) |
| B-4 | Subflow Composition | Апр 2026 | [`11-subflow-composition.md`](../../../platform/architecture/adr/11-subflow-composition.md) |

Каждый ADR имеет `Amendment · Brownfield Reconciliation` секцию с конкретными override-решениями.

**Future deliverable (V1.x):** ADR Idempotency Strategy (full Redis SET NX dedup для broadcast/financial use cases). Минимальный contract зафиксирован в ADR Message Routing.

## Implementation status

Актуальные чекбоксы и статусы реализации ведутся только в [`../../../platform/TASKS.md`](../../../platform/TASKS.md).
Исторический план Phase A–E см. в [implementation-plan.md](../../../platform/plans/flow-engine/implementation-plan.md).

---

## Связано с

- [[../../plans/flow-engine/implementation-plan]] — детальный план реализации
- [[../../plans/flow-engine/synthesis]] — итоговый синтез
- [[06-flow-engine]] — архитектура flow engine
- [[02-flow-engine-loop]] — диаграмма execution loop
- [[nodes/README]] — каталог нод
- [[08-concurrency-idempotency]] — concurrency и idempotency
- [[10-state-writer-semantics]] — ADR state writer semantics
- [[09-message-routing-concurrency]] — ADR concurrency
- [[diagrams/02-flow-engine-loop]] — диаграмма execution loop
- [[TASKS]] — задачи реализации
