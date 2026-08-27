# Node · `assign`

Запись значений. Объединяет SetAttribute и Transform.

**Type:** `assign`
**Version:** 1
**Idempotent:** yes

## Config

```json
{
  "operations": [
    {
      "target": "contact.full_name",
      "value": "{{contact.first_name}} {{contact.last_name}}"
    },
    {
      "target": "flow.code_attempts",
      "value": "{{flow.code_attempts}} + 1"
    },
    {
      "target": "contact.form.completed_at",
      "value": "{{system.now}}"
    }
  ]
}
```

**Поля:**

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `operations` | array (>=1) | yes | Список операций, выполняются по порядку |

**Operation:**

| Поле | Тип | Описание |
|------|-----|----------|
| `target` | **literal string** | Полный path куда писать (`contact.X`, `contact.group.X`, `flow.X`). **Не Expression**, без `{{...}}`. |
| `value` | Expression | Выражение для вычисления значения |

> **Patch v1.1:** `target` — литеральная строка, не Expression. Это инвариант для:
> - Validation (path известен на save time)
> - Group discovery (cache корректно инвалидируется, см. [05-group-storage.md](../05-group-storage.md))
> - Refactoring (статический анализ возможен)
>
> Динамический выбор target достигается через branch + multiple assign nodes.

## Output handles

- `success`

> **Patch v1.1:** только `success`. `assign` не имеет `error` handle. Все failures (structural conflict, reserved key violation, type mismatch) приводят к **session failed**. Инвариант: assign безопасен, если flow_definition прошёл validation. См. [06-validation.md](../06-validation.md), раздел 6.5.

## Behavior

> **Patch v1.3 (brownfield reconciliation D-3):** session-state мутации возвращаются через `result.stateChanges` (handler — pure function, engine применяет атомарно). Только `contact.*` writes идут immediate через ContactWriter.

1. Build local `$delta = []` (path => resolved value).
2. Для каждой operation по порядку:
   - Resolve `value` Expression через `$context->expressionEngine` + `$context->stateReader`
   - Routing по target prefix:
     - `flow.*` / `system.*` (whitelisted) / `rag.*` / `call.*` → добавляется в `$delta`. Handler делает `data_set($delta, $target, $value)` локально для read-after-write в рамках текущего execute.
     - `contact.*` → `$context->contactWriter->write($target, $value)` — immediate write в Contact (включая structural validation: reserved keys, leaf vs group conflict, depth ≤ 1)
   - Если writer/resolver бросает (тип несовместим, structural conflict, reserved key) → exception → session failed
3. Return `NodeExecutionResult::executed(sourceHandle: 'success', stateChanges: $delta)`.
4. Engine применяет `stateChanges` к `FlowSession.state` JSON и делает `UPDATE flow_sessions ... WHERE version = ?` в одной транзакции с version++. Optimistic-lock conflict → engine retries (handler идемпотентен — повторная запись того же значения = тот же state).

History instrumentation (state_change events) выполняется engine после применения `stateChanges` + ContactWriter — handler не вызывает logger напрямую (D-6).

### Read-after-write semantics

Sequential operations внутри одной ноды видят результаты предыдущих ops:

```json
{
  "operations": [
    {"target": "flow.counter", "value": "{{flow.counter}} + 1"},
    {"target": "flow.counter", "value": "{{flow.counter}} + 1"}
  ]
}
```

Outcome (если `flow.counter` был 0):
- Op 1: handler `read('flow.counter')` через reader → 0; computes 1; `data_set($delta, 'flow.counter', 1)` локально
- Op 2: handler resolve `{{flow.counter}}` смотрит сначала в `$delta` → 1; computes 2; `$delta['flow.counter'] = 2`
- Result.stateChanges = `['flow.counter' => 2]`
- Engine применяет → `session.state.flow.counter = 2`

Для `contact.*`: первый write коммитит в БД (через ContactWriter), второй read через `stateReader` возвращает свежий committed value (Contact модель refreshes).

## Validation flow_definition

- `target` — литеральная строка, **не содержит** `{{...}}` placeholders
- `target` начинается с `contact.` или `flow.` (другие namespaces — read-only для пользователя)
- `target` не нарушает reserved keys (см. [01-state-model.md](../01-state-model.md), раздел 1.4)
- `target` не превышает глубину 1 уровень группы для contact
- Path uniqueness across flow — см. [06-validation.md](../06-validation.md), раздел 6.5

---

## Связано с

- [[README]] — nodes README
- [[04-assign-node-migration]] — миграция assign ноды в builder
- [[10-state-writer-semantics]] — семантика записи в state
- [[05-backend-contract]] — backend контракт переменных
