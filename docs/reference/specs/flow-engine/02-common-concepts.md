# 02 · Common Concepts

## 2.1 Variable definition

Используется в Input и Assign nodes. Единый формат.

```json
{
  "variable": {
    "name": "input1",
    "storage": "contact",
    "group": "form"
  }
}
```

Поля:

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `name` | string | yes | Имя переменной (alphanumeric + underscore) |
| `storage` | enum | yes | `contact` или `session` |
| `group` | string\|null | no | Имя группы (alphanumeric + underscore). null = корень |

> **Patch v1.1:** поле `type` удалено. Compiler не использует тип для маппинга — путь резолвится по `storage + group + name`. Источник/parsing данных задаётся через `Input.input_type` (см. [nodes/02-input.md](nodes/02-input.md)). Assign Expression resolves в any JSON-serializable значение, кладётся as-is.

**Compiler model — где физически хранится:**

| storage | group | Resolved path |
|---------|-------|---------------|
| `contact` | null | `contact.<name>` (in `attributes`) |
| `contact` | `form` | `contact.form.<name>` (nested in `attributes`) |
| `session` | null | `flow.<name>` (in `flow_sessions.state.flow`) |
| `session` | `params` | `flow.params.<name>` |

## 2.2 Expression

Все значения которые могут быть переменными — это `Expression`. В V1 expression это **строка с placeholders**:

```
"Привет, {{contact.first_name}}"
"{{flow.code}}"
"{{contact.form.input1}} {{contact.form.input2}}"
```

Чистая строка без placeholders — литерал. Чистый placeholder — значение по path.

**Решение:** expression evaluation реализуется как pluggable strategy через `ExpressionEngineInterface`. Engine выбирается per-tenant через `tenant.settings.expression_engine`, snapshot-фиксируется в `flow_definitions.expression_engine`. V1 ships built-in engine `'template'` (regex `{{path}}` substitution), дополнительные (Symfony EL, Twig, custom plugin engines) добавляются по first business need. Полный контракт и rationale — см. ADR Expression Language (`docs/platform/architecture/adr/08-expression-language.md`).

**Sandbox scope в expression:**
- `system.*`
- `flow.*`
- `rag.*`
- `call.*`
- `contact.*` (читается через resolver)
- `module.<name>.*` (читается через DataAccessor)

## 2.3 Output handles

Каждый node возвращает `NodeExecutionResult` с `sourceHandle` — engine резолвит next node через edge lookup. Handlers — graph-unaware.

Стандартные handles per node — см. описания нод в `nodes/`.

## 2.4 Node JSON snapshot

Базовая структура одинакова для всех нод:

```json
{
  "id": "01HQ...",
  "type": "<node_type>",
  "version": 1,
  "config": {
    /* node-specific config */
  }
}
```

`id` — ULID per node в графе flow.
`type` — типа handler в registry.
`version` — версия контракта handler.
`config` — node-specific.

---

## Связано с

- [[01-state-model]] — state model
- [[03-node-handler-interface]] — интерфейс handler
- [[08-expression-language]] — expression language
