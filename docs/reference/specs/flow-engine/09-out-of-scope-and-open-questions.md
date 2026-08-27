# 09 · Out of Scope и Открытые вопросы

## 9.1 Out of Scope для V1

Следующие capabilities планируются в V1.x или позже:

- ComposeMessage node (объединено в send_message)
- Prompt node (LLM без RAG) — нет реальных кейсов, отложено
- Параметризованный subflow с input/output mapping — V2 после первого продакшена
- Fire-and-forget subflow — заменяется emit_event
- Custom validators в input через code — built-in validators only в V1
- Custom expression functions — стандартный engine without extensions
- attribute_group_definitions metadata table
- UI report builder поверх групп (V1.x)
- Cross-flow group consistency validation (V1.x)
- Group archiving lifecycle
- Глубина вложенности > 1 уровень для contact attribute groups
- Strict mode для `result_mapping` в call (`strict_mapping: true` → fail при missing path)
- Explicit `assistant_filter` для event triggers
- NLP-style date parsers ("завтра в 10")
- Cross-assistant subflow (V2, отдельный ADR)

Каждое — отдельный feature, не требует breaking changes к V1.

## 9.2 Открытые вопросы перед стартом реализации

> **Patch v1.1:** вопросы 2, 3, 4, 5 из v1.0 закрыты inline в спеке. Вопрос 1 (Expression engine) закрыт ADR Expression Language (`docs/platform/architecture/adr/08-expression-language.md`).

Открытых вопросов нет — все блокеры для Sprint 4 закрыты. См. [10-related-adrs.md](10-related-adrs.md) и
[implementation-plan.md](../../../platform/plans/flow-engine/implementation-plan.md).

## 9.3 Остаточные уточнения для ADR

Эти пункты не блокируют реализацию, но должны попасть в соответствующие ADR:

1. ~~**StateWriter routing на под-writers**~~ — закрыт ADR State Writer Semantics (Апрель 2026, см. `docs/platform/architecture/adr/10-state-writer-semantics.md`). Решение: immediate writes, independent transactions для Contact и Session, read-after-write через in-memory мутацию session-state и committed Contact reads.
2. ~~**Subflow depth определение**~~ — закрыт ADR Subflow Composition (Апрель 2026, см. `docs/platform/architecture/adr/11-subflow-composition.md`). Depth = длина chain (цепочка из N flows = depth N), max = 3, recursion запрещена.
3. **attempt_number автоинкремент** (V1.x ADR Idempotency Strategy): в V1 `attempt_number = 1` статически (см. ADR Message Routing & Concurrency Control). Когда понадобится Redis dedup — добавить инкремент в `system.node_attempts.<node_id>` на каждый запуск handler ноды; разведение vs `flow_sessions.version` (optimistic lock на UPDATE session) зафиксировать там же.
4. **success_when обобщить** (call ноды): рассмотреть generic форму `success_statuses: [200, 201, 404]` или `success_status_classes: ["2xx"]` вместо closed enum `2xx`/`any_response`/`2xx_or_4xx`.
5. **Idempotency Redis TTL ≥ max queue retention + grace** (V1.x ADR Idempotency Strategy): когда Redis SET NX dedup появится, TTL ключей должен быть ≥ максимального времени жизни сообщения в очереди + grace period, иначе при сильном backpressure ключ протухнет до retry.

---

## Связано с

- [[15-open-questions]] — открытые вопросы платформы
- [[README]] — flow engine README
- [[../../plans/flow-engine/synthesis]] — синтез
