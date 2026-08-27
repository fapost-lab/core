# ADR-11 — Subflow Composition

**Status:** Accepted
**Date:** Апрель 2026
**Контекст:** FaPost Phase 2 — Flow Engine, Sprint 6
**Связанные документы:** `docs/reference/specs/flow-engine/`, `docs/platform/architecture/adr/09-message-routing-concurrency.md`, `docs/platform/architecture/adr/10-state-writer-semantics.md`, Platform Architecture v2.2

---

## Context

Flow Engine поддерживает композицию: один flow может вызывать другой. Use cases:

- Переиспользование общих сценариев (сбор профиля, валидация даты, подтверждение действия)
- Декомпозиция сложных flows на управляемые подсценарии
- Параметризованные операции (выбор отдела, выбор пользователя для редактирования)
- Library patterns (общие компоненты)

Subflow — отдельный flow_definition, вызываемый из другого flow через `subflow` ноду. Wait mode: parent suspended, child выполняется в своей session, по завершении — parent resume.

Возникали вопросы:

1. Параметризация input/output — V1 или V1.1?
2. Cross-assistant subflow семантика
3. Lifecycle: parent expires_at vs child runtime
4. Cycle detection — save-time или runtime?
5. flow_version resolution — что если нет active version
6. Draft vs Published validation

После обсуждения зафиксированы решения, описанные в этом ADR.

## Decision

**Subflow в V1 — wait mode без параметров. Координация между parent и child через `contact.attributes`. Параметризация — V1.1 без breaking changes.**

Outline:

1. **Wait mode only.** Fire-and-forget делается через `emit_event`.
2. **V1 без параметров.** Snapshot structure готова к extension (`input_mapping`, `output_mapping` опциональные поля).
3. **Same-assistant only.** Cross-assistant subflow запрещены в V1, validator на publish refuses.
4. **Глубина max 3, recursion запрещена.** Detection через reverse index `flow_callgraph_edges`.
5. **flow_version всегда latest active** в момент child start. Pin specific version не поддерживается в V1.
6. **Soft validation для draft, strict для publish.** Draft saves всегда успешны (warnings), publish refuses при нарушениях.
7. **Parent expires_at extends** при child start если child timeout длиннее. После child resume — не сжимается обратно.
8. **Lock передаётся parent → child** через session lock chain. Heartbeat на child session во время выполнения.
9. **History parent + child независимы** (см. ADR State Writer). Parent логирует subflow_started/returned events.

## Rationale

### Wait mode единственный

Fire-and-forget — это не subflow, а emit_event. Triggers подписываются на event_type, запускают свои flows. Семантика «запустил, забыл» полностью покрывается через events.

Subflow без wait — лишний concept который пересекается с events.

### V1 без параметров

Координация через `contact.attributes` (с временными префиксами `_tmp_*`) покрывает реальные кейсы хоть и грязно. Это acceptable trade-off для V1:

- Storage уже есть, нет migration cost
- Большинство V1 flows — простые (онбординг, опросы), параметры не нужны
- Параметризация — feature которая правильно проектируется только после реального опыта использования subflow

V1.1 добавит `input_mapping` и `output_mapping` как опциональные поля snapshot. Existing V1 flows продолжают работать без изменений.

### Same-assistant only

Cross-assistant — отдельная сложность:
- Lock model: lock на (tenant, contact, **assistant**) — что значит "child assistant"?
- Routing: incoming message от contact — для какого assistant?
- Authorization: child assistant может не иметь доступа к данным parent

В V1 — strict refuse при validation на publish. Если flow_id указывает на flow зарегистрированный для другого assistant в том же tenant — publish refuses.

V2 — рассмотрим если появятся реальные кейсы.

### Глубина max 3, no recursion

Глубже 3 уровней — обычно сигнал плохого дизайна (over-decomposition). Жёсткое ограничение защищает от:

- Stack-like depth абстракции
- Длинных suspend chains (parent → A → B → C, parent ждёт всех)
- Сложного debugging

Recursion (direct A → A или indirect A → B → A) запрещена полностью. Циклы должны решаться через loop construct (V2), не subflow.

### Latest version always

Pin specific version (`flow_version: N`) добавляет complexity без явной необходимости в V1:

- Нужен UI для выбора version
- Нужна migration semantics при breaking changes child
- Backward compatibility — child должен sustain все referenced versions

Latest active — простая модель. Если callee breaks compatibility — все callers видят сразу. Это **виден** в логах и через testing pipeline.

Pin может быть добавлен в V1.x если появится use case (например, регулируемые financial flows где child v2 не должен использоваться без явного approval каждого parent).

### Soft draft validation, strict publish

Draft — рабочее пространство. Юзер может:
- Сохранять half-built flows
- Создавать заготовки
- Ссылаться на ещё не созданные subflow (потом создаст и опубликует)

Strict validation на каждый save draft = плохой UX. Публикация — точка commitment, всё должно быть валидно.

Validator на draft save:
- Выполняет все проверки
- Возвращает warnings вместо errors
- Save проходит независимо от warnings

Validator на publish:
- Те же проверки → blocking errors
- Publish refused при любом нарушении

### Parent extends expires_at

Parent должен пережить child. Если parent original expires_at < child start + child timeout, parent продлевается:

```
parent.expires_at = max(parent.expires_at, child.expires_at + 1h)
```

Buffer 1h для resume operation safety.

После child resume — parent.expires_at не сжимается обратно. Acceptable side effect: если child завершился рано, parent живёт extended немного дольше изначального плана. Это **simpler** чем tracking original_expires_at и его restoration.

### Lock transfer parent → child

Distributed lock на (tenant, contact, assistant) сохраняется через subflow. Но семантически "владение" lock переходит:

- При subflow start: parent в `paused_subflow`, не активен. Child становится active. Lock остаётся тот же ключ, но heartbeat поддерживается worker'ом который обрабатывает child.
- При child end: parent resume, lock остаётся, heartbeat возвращается parent worker.

Это требует careful implementation, но семантически чисто: один контакт = один lock = одна active session в каждый момент (parent или child, не оба).

### Independent history

Parent и child sessions используют свой `flow_definition.logging_enabled` независимо. Каждый flow author контролирует свои логи.

Parent history содержит **navigation events** (`subflow_started`, `subflow_returned`) с child_session_id. Это даёт:

- Трассировку «parent дошёл до subflow ноды, ждал, получил такой outcome»
- Навигацию к child history через session_id reference

Child history (если включена) — внутренние ноды child. Никакого автоматического merging парент-child историй.

## Snapshot Structure

### Subflow Node Config (V1)

```json
{
  "id": "01HQ...",
  "type": "subflow",
  "version": 1,
  "config": {
    "flow_id": "01HQ_collect_personal_data",
    "timeout": "PT24H"
  }
}
```

**Fields:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `flow_id` | string (ULID) | yes | Logical flow ID (НЕ flow_definition_id). Resolves к latest active version в runtime |
| `timeout` | string (ISO 8601 duration) | yes | Child timeout. По истечении — force-fail с `failed` handle |

### V1.1 Extension (Reserved Fields)

```json
{
  "config": {
    "flow_id": "...",
    "timeout": "PT24H",
    "input_mapping": {                    // V1.1
      "user_id": "{{flow.target_user_id}}",
      "context": "{{contact.tier}}"
    },
    "output_mapping": {                   // V1.1
      "flow.calculated_price": "result.price",
      "flow.applied_discount": "result.discount"
    }
  }
}
```

V1 implementation **игнорирует** `input_mapping` / `output_mapping` если они присутствуют (forward compatibility). V1.1 implementation использует.

### Output Handles

Subflow node имеет 3 output handles:

- `success` — child end node вернул status=success
- `cancelled` — child end node вернул status=cancelled
- `failed` — child end status=failed, OR child force-failed по timeout, OR child crashed

Edges из subflow node connecting handles к next nodes.

## Lifecycle

### Subflow Start

При выполнении subflow ноды engine:

1. Resolve `flow_id` → находит latest active flow_definition
2. Если active definition не найден → session failed с error «Subflow target not active»
3. Создаёт child flow_session:

```sql
INSERT INTO flow_sessions (
    id, tenant_id, contact_id, assistant_id,
    flow_id, flow_definition_id,
    parent_session_id, parent_resume_node_id,
    state, status, version, expires_at, started_at
) VALUES (
    <new ulid>,
    <parent.tenant_id>,
    <parent.contact_id>,
    <parent.assistant_id>,        -- inherited, immutable
    <resolved flow_id>,
    <resolved definition_id>,
    <parent.id>,                   -- key navigation field
    <subflow node id>,             -- куда вернуться
    '{}',                          -- empty state initially
    'active',
    1,
    <now() + timeout>,
    now()
);
```

4. UPDATE parent:

```sql
UPDATE flow_sessions
SET status = 'paused_subflow',
    expires_at = GREATEST(expires_at, <child.expires_at> + interval '1 hour'),
    version = version + 1
WHERE id = <parent.id>;
```

5. Lock heartbeat теперь поддерживается child execution worker
6. Если parent.flow_definition.logging_enabled — write history event:

```
{
  event_type: 'subflow_started',
  session_id: <parent.id>,
  node_id: <subflow node id>,
  metadata: {
    child_session_id: <child.id>,
    child_flow_id: <resolved flow_id>,
    child_definition_id: <resolved definition_id>
  }
}
```

7. Engine начинает execution child от entry node

### Subflow Execution

Child выполняется как обычный flow в своей session. Все ноды работают идентично top-level flows. Никаких отличий с точки зрения handler.

Routing инвариант (см. ADR Message Routing): incoming messages для contact с paused parent → пересылаются child session (если она в waiting_input).

```
findActiveSession(tenant, contact, assistant):
  candidates = sessions WHERE (tenant, contact, assistant) match
                          AND status IN (active, waiting_input, paused_subflow)

  Если есть session со status=paused_subflow:
    Найти её child (parent_session_id = paused_subflow.id, status IN active/waiting_input)
    Return child

  Иначе:
    Return single non-paused session
```

### Subflow End

Когда child достигает `end` node — EndNodeHandler выполняет sub-flow specific logic:

1. UPDATE child SET status = 'ended', end_status = config.status, ended_at = now()
2. Если child.parent_session_id != null:
   a. LOAD parent session FOR UPDATE
   b. Verify parent.status = 'paused_subflow' (если нет — log inconsistency, child всё равно ends)
   c. UPDATE parent:
      ```sql
      UPDATE flow_sessions
      SET status = 'active',
          current_node_id = <parent_resume_node_id>,
          version = version + 1
      WHERE id = <parent.id>;
      ```
   d. Resume parent через handle согласно child status:
      - success → 'success' handle
      - cancelled → 'cancelled' handle
      - failed → 'failed' handle
   e. Если parent.flow_definition.logging_enabled — write history event:
      ```
      {
        event_type: 'subflow_returned',
        session_id: <parent.id>,
        node_id: <subflow node id>,
        metadata: {
          child_session_id: <child.id>,
          outcome: 'success' | 'cancelled' | 'failed'
        }
      }
      ```

### Timeout Handling

Scheduled job `flow.subflow.timeout` (раз в минуту) проверяет:

```sql
SELECT s.* FROM flow_sessions s
WHERE s.parent_session_id IS NOT NULL
  AND s.status IN ('active', 'waiting_input')
  AND s.expires_at < now();
```

Для каждого expired child:

1. UPDATE child SET status = 'expired', end_status = 'failed', ended_at = now()
2. Resume parent через `failed` handle (как при normal end with failed status)
3. Log timeout event в parent history

## Validation

### Save Draft (Soft)

При сохранении draft (`is_active = false`) — validator выполняет проверки и возвращает **warnings**, не блокируя save.

Warning categories:

- **Reference warnings:** subflow.flow_id указывает на несуществующий flow или flow без active version
- **Cycle warnings:** обнаружен потенциальный цикл (включая через draft chain)
- **Depth warnings:** call graph depth > 3
- **Cross-assistant warnings:** subflow указывает на flow другого assistant
- **Validation warnings:** другие issues (missing edges, orphan nodes, etc — общие validation для всех нод)

Save draft всегда успешен. UI показывает warnings рядом с problematic nodes.

### Publish (Strict)

При publishing (`is_active` transition false → true) — валидатор выполняет те же проверки + блокирует publish при любых нарушениях.

**Publish refuse cases:**

- subflow.flow_id не существует в этом tenant
- subflow.flow_id не имеет active published version
- subflow.flow_id принадлежит другому assistant
- Cycle detected (через reverse index)
- Depth > 3 в любой ветке call graph
- Любые structural validation errors (orphan nodes, missing handles, etc)

Publish action atomic:

```
BEGIN TRANSACTION
  Lock flow_callgraph_edges (FOR UPDATE for affected rows)

  Validate:
    - Resolve direct callees
    - BFS recursive callees через flow_callgraph_edges
    - Check cycles, depth, cross-assistant, existence
    - If violation → ROLLBACK + return errors

  Update flow_callgraph_edges:
    - DELETE old edges for this flow_id (previous active version)
    - INSERT new edges

  UPDATE flow_definitions:
    - SET is_active = false WHERE flow_id = X AND is_active = true
    - SET is_active = true WHERE id = <new version id>
COMMIT
```

FOR UPDATE на edges предотвращает race с другими concurrent publishes которые могут создать cycle между ними.

### Cycle Detection Algorithm

Reverse index:

```sql
CREATE TABLE flow_callgraph_edges (
    caller_flow_id ulid NOT NULL,
    callee_flow_id ulid NOT NULL,
    caller_definition_id ulid NOT NULL REFERENCES flow_definitions(id),
    PRIMARY KEY (caller_flow_id, callee_flow_id, caller_definition_id)
);

CREATE INDEX idx_callgraph_callee ON flow_callgraph_edges (callee_flow_id);
```

Заполняется при publish flow_definition: extract все subflow.flow_id refs → INSERT/DELETE edges atomically с публикацией.

**При publish flow B:**

1. Extract callees of B (new): `direct_callees = [...]`
2. BFS forward from B:
   - For each callee, get **its** callees from edges table
   - Recursive до depth 3
   - If B appears в transitive closure → cycle
3. BFS reverse from B:
   - Get callers of B (`SELECT caller_flow_id FROM edges WHERE callee_flow_id = B`)
   - For each caller — recursively check that path through B doesn't exceed depth 3
4. If any check fails → publish refuses

**Same-assistant check:**

Для каждого callee — load its flow и check что assistant_id == B's assistant_id. Если any callee другого assistant → refuse.

### Cascade Refuse Strategy

Если изменения B нарушают существующие parent flows (через depth violation), publish B refuses с указанием affected parents:

> "Publishing flow B нарушит flows: X, Y. Цепочка: X → A → B (depth 3). Обновите callers перед публикацией B."

Admin отвечает за migration. Это compatible с принципом "publish — точка commitment, всё должно быть валидно".

### Active Sessions Не Затронуты

Validation касается только **новых** sessions. Существующие running sessions работают по своему `flow_definition_id` snapshot:

- Session держит ссылку на конкретный snapshot
- Snapshot immutable (даже если новые версии publish'ятся)
- Active subflow chains продолжают работать до своего natural completion

## Concurrency

### Lock Inheritance

Subflow start не освобождает lock и не берёт новый. Lock на (tenant, contact, assistant) **continues** seamlessly:

- Pre-subflow: worker A holds lock, processes parent
- Subflow start: worker A creates child, marks parent paused_subflow, **continues holding lock**
- Child execution: worker A processes child, lock heartbeat normal
- Subflow end: worker A processes parent resume, lock heartbeat continues
- Parent end: worker A releases lock

Один worker, один lock, через всю chain. **Никаких изменений lock semantics.**

### Multiple Workers

Если parent execution приостановлена (waiting_input), lock освобождается. Incoming message от contact:
- Worker B берёт lock
- Routing: видит parent в paused_subflow → находит child в waiting_input → message processed by child
- Worker B держит lock на время child execution chain

Lock работает на **session chain level**, не на конкретной session. Один контакт = один active execution в любой момент времени, regardless parent/child.

### Optimistic Lock

Каждая session со своим version. Subflow start:

```
UPDATE child INSERT (version = 1)
UPDATE parent SET status='paused_subflow', version = parent.version + 1
```

Эти две операции в одной транзакции. Optimistic lock на parent.version защищает от concurrent updates parent.

Subflow end:

```
UPDATE child SET status='ended', version = child.version + 1
UPDATE parent SET status='active', current_node = resume_node, version = parent.version + 1
```

В одной транзакции. Optimistic locks на обе.

## Failure Modes

### Child crashed mid-execution

**Scenario:** worker holds child execution, crashes до child completion.

**Behavior:**
1. Lock on (tenant, contact, assistant) eventually expires (TTL без heartbeat)
2. Job retry через Horizon
3. New worker takes job → acquires lock
4. Routing: видит parent в paused_subflow → находит child active → resumes child execution
5. Child handler retried (idempotent contract)

**Outcome:** child eventually completes или fails.

### Parent.expires_at < now() while child running

**Scenario:** parent extension оказался недостаточным, parent expired.

**Behavior:**
1. Cleanup job обнаруживает parent expired
2. Если parent.status = 'paused_subflow' и child active → **не трогать parent**, продолжить child
3. Child run наестественно (success/fail/timeout)
4. На child end — попытка resume parent через update SET status='active'
5. Parent.expires_at обновляется или session проверяет — если expired детектируется здесь → parent помечается expired, child resume completes но parent не resumes выходом
6. Log inconsistency для investigation

**Mitigation:** проверка extension formula на корректность. Если возникает — bug в коде, не expected.

### Cycle создан runtime (impossible нормально)

**Scenario:** через DB intervention или bug, циклическая chain существует.

**Behavior:**
- При child start engine не делает runtime depth check
- Subflow start succeed
- Recursion chain создаётся: A → B → C → A...
- Каждый уровень creates new session row
- Parent.expires_at extends each time

**Mitigation:** save-time validation предотвращает creation cycles нормальными путями. DB intervention bypassing — не covered by application logic.

В V1.x можно добавить runtime safety check (count chain depth по parent_session_id chain) для defensive programming.

### Subflow target deleted между save parent и runtime

**Scenario:** parent сохранён ссылающимся на flow X. Admin удалил/deactivated все versions X. Parent runs, доходит до subflow ноды.

**Behavior:**
1. Engine resolves flow_id → no active definition
2. Session failed с error «Subflow target not active»
3. Parent помечен failed

**Mitigation:** publish-time validation проверяет existence. Между publish и runtime — race window. Acceptable в V1 (rare, recovery через re-publish callee).

## V1 Scope

### В V1

- `subflow` node type (one of 10 core node types)
- Wait mode lifecycle (parent paused → child active → child end → parent resume)
- Same-assistant constraint enforcement
- Depth limit ≤ 3, recursion prevention
- `flow_callgraph_edges` reverse index
- Save draft (soft validation, warnings)
- Publish strict validation (refuses on violations)
- Latest active version resolution
- `flow_session_history` events: subflow_started, subflow_returned (если parent logging enabled)
- `flow_sessions.parent_session_id` index для queries
- Scheduled timeout job для expired child handling
- Routing rule: incoming message для paused_subflow parent → child

### Не в V1

- **Параметризация (input_mapping, output_mapping):** V1.1
- **Pin specific version (`flow_version: N`):** V1.x по требованию
- **Cross-assistant subflow:** V2 если возникнет need
- **Loop construct через subflow:** V2 (отдельный mechanism)
- **Runtime depth check:** V1.x как safety net
- **Subflow с альтернативными outcomes** beyond success/cancelled/failed: V2
- **Multiple parallel children:** never (model violates single-active-execution invariant)

## Migration Path V1 → V1.1

### Adding Parameters

V1.1 расширение:

```json
{
  "config": {
    "flow_id": "...",
    "timeout": "PT24H",
    "input_mapping": {
      "<param_name>": "<expression>"
    },
    "output_mapping": {
      "<target_path>": "<source_path_in_child>"
    }
  }
}
```

Engine V1.1 reads `input_mapping`:
- Resolves expressions против parent state
- Writes resolved values в child.state.flow.* при child create

Engine V1.1 reads `output_mapping`:
- При child end — extracts values из child.state по source_path
- Writes в parent.state по target_path после parent resume

V1 flows (без mapping fields) работают неизменно — engine V1.1 видит missing mappings → no-op.

### Output Schema в Child

Child flow_definition в V1.1 получает optional metadata:

```json
{
  "is_callable": true,
  "input_schema": {...},
  "output_schema": {...}
}
```

V1 child flows (без schema) — accept any input, return any output (loose typing). V1.1 callers могут validate если schema present.

## Test Strategy

### Unit Tests

- Subflow node config validation
- Reverse index updates на publish
- Cycle detection algorithm (BFS forward + reverse)
- Depth calculation
- Cross-assistant detection
- Latest active version resolution
- flow_id existence check

### Integration Tests

- Single-level subflow: parent → child → end → parent resume → end
- Two-level subflow: parent → A → B → end → A resume → end → parent resume
- Three-level subflow at depth limit
- Cycle attempt at publish (refuse)
- Depth-4 attempt at publish (refuse)
- Cross-assistant attempt (refuse)
- Save draft с invalid references (warnings only, save succeeds)
- Publish с invalid references (errors block)
- Child timeout → parent failed handle
- Parent expires_at extension verified
- Routing: incoming message during paused_subflow → reaches child
- Concurrent publishes с potential cross-cycles (FOR UPDATE protection)
- History events: subflow_started, subflow_returned correctly logged

### Failure Mode Tests

- Worker crash during child execution → retry recovery
- Subflow target deactivated between save and runtime → graceful failed
- Parent expired during child run → child completes, parent state inconsistent (logged)

### Acceptance Criteria

- [ ] All unit tests pass
- [ ] All integration tests pass
- [ ] Manual test: parent calls reusable subflow (e.g., CollectPersonalData), child writes to contact.attributes, parent reads after resume → values correct
- [ ] Manual test: cycle attempt → publish error message clear
- [ ] Manual test: depth violation → publish error indicates chain
- [ ] Manual test: incoming message during child execution → handled by child correctly
- [ ] Performance test: 100 concurrent subflow chains running → lock contention acceptable

## Consequences

### Positive

- Composition без ввода полноценной call/return semantic complexity
- Reusable subflows возможны в V1 (через contact storage coordination)
- Strict publish validation предотвращает runtime cycle/depth errors
- Soft draft validation = good UX для work-in-progress
- Independent histories дают audit gradient (что логируется per-flow)
- Future-compatible с параметризацией V1.1

### Negative

- V1 без параметров — workaround через `_tmp_*` в contact.attributes (грязно)
- Cross-assistant subflow невозможны (limit для некоторых cross-domain сценариев)
- Pin version отсутствует — каждый callee breaking change ломает все callers
- Cycle detection on publish race может быть expensive для большого тенанта (FOR UPDATE на edges)

### Neutral

- Reverse index `flow_callgraph_edges` — новая таблица, нужно maintain consistency с flow_definitions
- Lock semantics через chain — implementation детали не влияют на user-visible behavior

## References

- `docs/reference/specs/flow-engine/nodes/08-subflow.md` — subflow node spec
- `docs/platform/architecture/adr/09-message-routing-concurrency.md` — lock semantics, routing rules
- `docs/platform/architecture/adr/10-state-writer-semantics.md` — history logging, parent/child independence
- `docs/platform/architecture/adr/08-expression-language.md` — будет использоваться в V1.1 для input/output mapping
- Platform Architecture v2.2 — раздел 5 (concurrency), раздел 7 (message pipeline)

---

**Document final. Implementation ready for Sprint 6.**

---

## Amendment · Brownfield Reconciliation (April 2026)

ADR применяется как написано — все примитивы net-new (Phase C-4). Уточнения:

- **`flow_callgraph_edges` table** — создана миграцией `2026_05_06_000004` (Phase A-2 ✓).
- **`flow_sessions.parent_session_id`/`parent_resume_node_id`/`end_status`** — добавлены миграцией `2026_05_06_000002` ✓ с partial index на `parent_session_id`.
- **`flow_sessions.status` enum extension** — `paused_subflow` добавлен в миграции `2026_05_06_000003` ✓.
- **Validation framework**: existing `ValidateFlowService` + `PublishFlowService` + `SaveDraftService` — extends в Phase C-4 субflow-rules (`SubflowCycleRule`, `SubflowSameAssistantRule`, `SubflowDepthRule`, `SubflowTargetExistsRule`). Soft draft / strict publish дифференциация — добавляется в существующий validator.
- **`flow_session_history.subflow_started`/`subflow_returned`** events emitted via Engine + EndHandler в Phase C (см. ADR State Writer Semantics § History Logging).
- **Lock chain inheritance**: используется существующий `SessionLockManager` (Phase A-5) — никакой передачи, lock на `(tenant, contact, assistant)` остаётся неизменным через subflow chain.

Реализация subflow handler + lifecycle service + cycle validator в `docs/plans/flow-engine/implementation-plan.md` Phase C-4.

---

## Связано с

- [[08-subflow]] — спека ноды subflow
- [[06-subflow-lifecycle]] — диаграмма lifecycle subflow
- [[08-expression-language]] — expression language для передачи параметров
- [[06-flow-engine]] — flow engine архитектура
- [[specs/flow-engine/nodes/08-subflow]] — спека ноды subflow
- [[diagrams/06-subflow-lifecycle]] — диаграмма lifecycle
- [[10-state-writer-semantics]] — state writer semantics
