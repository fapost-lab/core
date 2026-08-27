# Node · `subflow`

Вызов другого flow с приостановкой parent до завершения child.

**Type:** `subflow`
**Version:** 1
**Idempotent:** complex (см. behavior)

## Config (V1)

```json
{
  "flow_id": "01HQ_collect_personal_data",
  "timeout": "PT24H"
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `flow_id` | string | yes | ULID logical flow id (НЕ flow_definition_id). Resolves к **latest active** version в runtime |
| `timeout` | string | yes | ISO 8601 duration. По истечении child force-failed |

> **Patch v1.2 (ADR Subflow Composition):** поле `flow_version` удалено из V1 config. Pin specific version не поддерживается в V1 (latest active always). Если понадобится — добавится как опциональное поле в V1.x по first business need.

### V1.1 reserved fields (forward compatibility)

Snapshot структура готова к extension:

```json
{
  "config": {
    "flow_id": "...",
    "timeout": "PT24H",
    "input_mapping": {                    // V1.1 — V1 ignores
      "user_id": "{{flow.target_user_id}}"
    },
    "output_mapping": {                   // V1.1 — V1 ignores
      "flow.calculated_price": "result.price"
    }
  }
}
```

V1 implementation **игнорирует** `input_mapping` / `output_mapping` если присутствуют. V1 координация parent ↔ child — через `contact.attributes` (с временными `_tmp_*` префиксами). См. [`../11-subflow-composition.md`](../../../architecture/adr/11-subflow-composition.md) "Migration Path V1 → V1.1".

## Output handles

- `success` — child завершён через end node со status=success
- `cancelled` — child завершён через end со status=cancelled
- `failed` — child failed unexpectedly, либо timeout, либо end со status=failed

## V1 ограничения

- **Без параметров:** parent НЕ передаёт child input. Child НЕ возвращает parent output. Координация — через `contact.*` или модульные данные.
- **Один child за раз:** parent в `paused_subflow` не может вызвать ещё один subflow параллельно. Subflow всегда sequential.
- **Глубина max 3:** depth = длина цепочки flows. A → B → C допустимо (depth 3). Глубже refuse при сохранении flow_definition.
- **Direct и indirect recursion запрещены:** flow A → A или A → B → A — refuse при сохранении.
- **Wait mode only:** fire-and-forget делается через `emit_event`, не subflow.
- **Без cross-assistant:** child наследует `assistant_id` parent immutably. Cross-assistant subflow — V2, отдельный ADR.

## Behavior

1. Resolve `flow_id` → находит **latest active** flow_definition (pin version не поддерживается в V1). Если active version не найден → session failed с error «Subflow target not active».
2. INSERT child flow_session:
   - `id` = generated ULID
   - `tenant_id` = `parent.tenant_id` (inherited)
   - `contact_id` = `parent.contact_id` (inherited)
   - `assistant_id` = `parent.assistant_id` (inherited, **immutable**)
   - `parent_session_id` = `parent.id`
   - `parent_resume_node_id` = subflow node id
   - `flow_definition_id` = резолвленный snapshot id
   - `state.flow` = {} (пустой)
   - `state.system.started_at` = `now()`
   - `expires_at` = `now() + timeout`
   - `status` = `active`
3. **Parent expiry extension:** если `parent.expires_at < child.expires_at` → расширить `parent.expires_at = child.expires_at + 1h` (buffer для resume operation).
4. UPDATE parent_session:
   - `status` = `paused_subflow`
   - `expires_at` = (extended если шаг 3)
   - `version` = `version + 1`
5. Distributed lock остаётся на `(tenant, contact, assistant)` — семантически "владеет" child пока parent paused. Технически lock тот же ключ Redis, нет физической передачи — логически "владение" переходит между sessions.
6. Запустить child через FlowEngine с `child.session_id`

> **Patch v1.1:** явная inheritance `tenant_id`/`contact_id`/`assistant_id` от parent (раздел 2). Sub-block "Parent expiry extension" (раздел 3) — parent не должен умирать пока child жив.

## Routing инвариант

Когда incoming message приходит от contact — engine `findActiveSession(tenant, contact, assistant)`:
- Если найдена session со status=`paused_subflow` — найти её child (status IN (`active`, `waiting_input`)) — message отправляется child
- Иначе — top-level session

## Завершение child

Когда child достигает `end` node — EndNodeHandler выполняет sub-flow specific logic:

1. UPDATE child SET `status = 'ended'`, `ended_at = now()`
2. Если `child.parent_session_id != null`:
   - LOAD parent session FOR UPDATE
   - Проверка `parent.status == 'paused_subflow'`. Если нет → log inconsistency, child всё равно ends.
   - UPDATE parent:
     - `status` = `active`
     - `current_node_id` = `parent_resume_node_id`
     - `version` = `version + 1`
   - Resume parent через handle:
     - `end.status=success` → `success`
     - `end.status=cancelled` → `cancelled`
     - `end.status=failed` → `failed`

## Timeout handling

Scheduled job `flow.subflow.timeout` каждую минуту проверяет sessions со status=`paused_subflow` где `expires_at < now()`:

```sql
SELECT * FROM flow_sessions
WHERE status = 'paused_subflow'
  AND expires_at < now()
  AND id IN (
    SELECT parent_session_id FROM flow_sessions
    WHERE status IN ('active', 'waiting_input')
      AND parent_session_id IS NOT NULL
  );
```

Если parent expired И child still running:
- Force-end child со `status=failed`
- Resume parent через `failed` handle
- Log inconsistency (этого не должно быть если parent expiry extension работает правильно)

## Cycle prevention

> **Patch v1.1:** referential integrity через reverse-index, не только forward DFS на save.

### Reverse index

```sql
CREATE TABLE flow_callgraph_edges (
    caller_flow_id ulid NOT NULL,
    callee_flow_id ulid NOT NULL,
    caller_definition_id ulid NOT NULL REFERENCES flow_definitions(id),
    PRIMARY KEY (caller_flow_id, callee_flow_id, caller_definition_id)
);

CREATE INDEX idx_callgraph_callee ON flow_callgraph_edges (callee_flow_id);
```

Заполняется при save flow_definition: extract все `subflow.flow_id` → INSERT/DELETE.

### Soft draft / strict publish validation

> **Patch v1.2 (ADR Subflow Composition):** проверки разделены по точке применения.

**Save draft** (`is_active = false`): validator выполняет все проверки, возвращает **warnings**, save проходит независимо. UI показывает warnings рядом с problematic nodes — это рабочее пространство.

**Publish** (`is_active` transition false → true): те же проверки, но любое нарушение **блокирует** publish.

Publish refuse cases:
- `subflow.flow_id` не существует в этом tenant
- Нет active published version у callee
- Callee принадлежит другому assistant (cross-assistant запрещён в V1)
- Cycle detected (включая через transitive closure)
- Depth > 3 в любой ветке call graph
- Любые structural validation errors (см. [06-validation.md](../06-validation.md))

### Publish procedure (atomic)

```
BEGIN TRANSACTION
  SELECT FROM flow_callgraph_edges WHERE callee_flow_id IN (affected) FOR UPDATE
    -- защищает от race с concurrent publishes которые могут создать cross-cycles

  Validate:
    - Resolve direct callees of B
    - BFS forward through edges → up to depth 3
    - BFS reverse from B → check каждый caller, его depth после B's update ≤ 3
    - Check cycles, cross-assistant, existence
    - If violation → ROLLBACK + return errors

  Update flow_callgraph_edges:
    - DELETE edges from previous active version of flow_id
    - INSERT edges from new version

  UPDATE flow_definitions:
    - SET is_active = false WHERE flow_id = B AND is_active = true
    - SET is_active = true WHERE id = <new version>
COMMIT
```

### Cascade refuse strategy

Save B refuses, если затрагивает любого caller. UI показывает:

> Сохранение нарушит flows: X, Y. Цепочка: A → B → X (depth 4).
> Обновите эти flows перед сохранением B.

**Why cascade refuse:**
- Cleaner semantics: либо save удался и всё validated, либо ничего не изменилось
- Меньше runtime surprise (ни один flow не работает в подвешенном состоянии)
- Легче UI/UX: явная ошибка с указанием как исправить

Альтернатива (mark-as-invalid каскад) — V1.x как fallback при жалобах на UX.

### Active sessions

Активные sessions не затрагиваются validation:
- Они работают по своему `flow_definition_id` snapshot
- Snapshot immutable — старые версии остаются в `flow_definitions` для running sessions
- Validation касается только **новых** sessions, которые стартуют после save

## Validation flow_definition

- `flow_id` существует (callable flow)
- Call graph: depth ≤ 3, no cycles
- Все callers нового flow validated на depth не превышен
- Strategy: cascade refuse при любом нарушении

---

## Связано с

- [[README]] — nodes README
- [[06-subflow-lifecycle]] — диаграмма lifecycle subflow
- [[11-subflow-composition]] — ADR по композиции subflow
- [[08-expression-language]] — expression language для передачи параметров
- [[07-emit-event]] — альтернатива через события
- [[diagrams/06-subflow-lifecycle]] — диаграмма lifecycle
- [[specs/flow-engine/README]] — обзор flow engine
