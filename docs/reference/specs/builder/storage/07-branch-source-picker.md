# 07 · Branch — dropdown переменных + source picker

**Зависит от:** 02 (User variables), 06 (общая модель источников)
**Блокирует:** —
**Слой:** builder (Vue)

## Цель

`Branch` (Condition) сейчас даёт ввести в operand сырое выражение `{{flow.foo}}` или `module.hr.department`. Заменить на UI из спеки (раздел 5):
- Если operand — пользовательская переменная → простой dropdown по имени, без выбора источника.
- Если operand — что-то другое → Source picker (Contact field / RAG / API / Last user message / Module).

## Текущее состояние

`ConditionConfig.vue` (override) рендерит rule builder. Operand — text input для path-выражения. Operator — select. Right-side value — text input (с поддержкой template).

`BranchNodeHandler` через `DataAccessorRegistry` резолвит namespace `module.*`; остальные пути читаются через `data_get($state, $path)`.

## Что меняем

### 1. UI — operand picker с двумя режимами

```
IF  [user variable ▾]                       (default mode)
     ─────
     code (Temporary)              ← user-defined vars
     overall_score (Contact)
     full_name (Contact)
     ─────
     + Other source...             ← раскрывает source picker

     equals  [...]
THEN ...
```

Опция `+ Other source...` переключает в source-picker mode:

```
IF  [Source: ▾]
     ─────
     📇 Contact field
     🧠 RAG result
     ⚡ API response
     💬 Last user message
     🏢 HR data (module — если активирован)
     ─────

  [field ▾]  equals  [...]
```

### 2. Compilation

UI → snapshot для rule:

User variable mode:
```json
{
    "left": { "ref": "user_variable", "name": "code" },
    "operator": "eq",
    "right": "1234"
}
```

Source mode:
```json
{
    "left": { "ref": "source", "source": "rag", "field": "found" },
    "operator": "eq",
    "right": true
}
```

Backend (BranchNodeHandler) разворачивает `left.ref` в expression:
- `user_variable` + name → resolver lookup → `contact.attributes.{group}.{name}` или `flow.{name}`
- `source` + source/field → известный path (например `rag.found`, `system.last_user_message`, `module.hr.department`)

### 3. Decompilation legacy

Старый rule с `left: "{{flow.code}}"` или `left: "rag.answer"` → distinguish:
- Path начинается с одного из source-prefix'ов (`rag.`, `call.`, `module.*`, `system.`) → source mode.
- Иначе — пытаемся mapping через user variables: ищем в дескрипторах текущего flow переменную с подходящим path → user_variable mode.
- Если не нашли — fallback в source mode с raw path (отображается с предупреждением «unknown source»).

### 4. Известные источники

| Source | Доступные fields |
|--------|------------------|
| `contact` | каждое поле из `contact.attributes` (вся структура группировки) + canonical поля контакта |
| `rag` | `found`, `confidence`, `answer`, `intent` |
| `call` | `last.status`, `last.body`, `last.headers` |
| `system` | `last_user_message`, `language`, `retry_count` |
| `module.*` | по registered `DataAccessor::supportedKeys()` |

Эти descriptors уже частично знают handlers; единый источник — `useFlowVariables` extension (задача 06) + новый composable `useConditionSources`.

## Файлы

- `resources/js/builder/components/editor/config/overrides/ConditionConfig.vue` — заменить operand input
- `resources/js/builder/components/editor/config/overrides/ConditionOperandPicker.vue` — новый sub-component
- `resources/js/builder/composables/useConditionSources.ts` — собирает known sources
- `app/Domains/Flow/Handlers/BranchNodeHandler.php` — поддержать новый формат `left: { ref, ... }` + legacy
- `app/Domains/Flow/Validation/FlowDefinitionValidator.php` — валидация формы rule

## Acceptance

- Существующий branch с `left: "{{flow.code}}"` открывается как user_variable выбор `code` (Temporary).
- Существующий branch с `left: "rag.found"` → source mode, source=RAG, field=found.
- Создание новой rule — default mode user_variable, dropdown по всем переменным flow.
- `+ Other source...` показывает только активные источники тенанта (модули — только активированные).
- Runtime branch выполняется одинаково для legacy и нового формата.

## Out of scope

- Multi-condition AND/OR (V1.x)

---

## Связано с

- [[03-branch]] — спека branch ноды
- [[06-variable-picker-rework]] — variable picker
- [[05-backend-contract]] — backend контракт
- Custom expressions (advanced toggle) — пока не ставим, спека этого не упоминает.
