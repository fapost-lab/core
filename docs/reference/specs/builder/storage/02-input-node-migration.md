# 02 · Input node — миграция на VariableStorageEditor

**Зависит от:** 01
**Блокирует:** 06 (variable picker), 07 (branch)
**Слой:** builder (Vue) + резолвер бэкенда (см. 05)

## Цель

Заменить текущий mono-input `state-picker` (`flow.foo`) в Input config на `VariableStorageEditor`. После — пользователь не вводит сырые пути, только Name/Type/Save-to/Group.

## Текущее состояние

`InputConfig.vue` (override) показывает:
- Question text
- Expected type select
- `save_to` — mono input типа state-picker (placeholder `flow.variable_name`)
- on_invalid message + retry_limit
- Validation rules (regex, min_length, max_length)

`InputNodeHandler.php::execute()` пишет в state по `save_to` как есть (полный path).

## Что меняем

### 1. UI — заменить mono input на VariableStorageEditor

```vue
<VariableStorageEditor
    v-model="variable"
    :type-options="INPUT_TYPES"
    :known-groups="knownGroups"
    show-storage
    show-group
/>
```

`INPUT_TYPES` в Vue компоненте:
```ts
const INPUT_TYPES = [
    { value: 'text',     label: 'Text' },
    { value: 'number',   label: 'Number' },
    { value: 'email',    label: 'Email' },
    { value: 'phone',    label: 'Phone' },
    { value: 'contact',  label: 'Contact (button)' },
    { value: 'select',   label: 'Select (from buttons)' },
    { value: 'confirm',  label: 'Yes/No' },
    { value: 'file',     label: 'File' },
    { value: 'photo',    label: 'Photo' },
    { value: 'location', label: 'Location' },
    { value: 'date',     label: 'Date' },
]
```

Тип ноды и `expected_type` — это разные понятия в текущей схеме (см. node taxonomy в CLAUDE.md). В UI для автора слиты: то что выбирает в Type — это и есть `expected_type`. Compiler разворачивает в нужное поле.

### 2. Compilation UI → JSON

Editor возвращает:
```ts
{ name: 'code', type: 'number', storage: 'contact', group: 'survey_q1' }
```

Compilation в node config:
```json
{
    "type": "input",
    "config": {
        "question": "...",
        "expected_type": "number",
        "variable": {
            "name": "code",
            "storage": "contact",
            "group": "survey_q1"
        },
        "validation": { ... },
        "on_invalid": { ... }
    }
}
```

### 3. Decompilation JSON → UI (legacy support)

При загрузке существующего flow:
- Если в config есть `variable` → используем как есть.
- Иначе legacy: смотрим `save_to`:
  - `save_to: "flow.code"` → `{ name: 'code', storage: 'session', group: null }`
  - `save_to: "contact.code"` → `{ name: 'code', storage: 'contact', group: null }`
  - `save_to: "contact.survey_q1.score"` → `{ name: 'score', storage: 'contact', group: 'survey_q1' }`
  - `save_to: "code"` (без префикса) → `{ name: 'code', storage: 'session', group: null }` (по дефолту был flow)

При первом редактировании ноды compiler перезаписывает в новую форму.

### 4. Backend (см. задача 05)

`InputNodeHandler` должен принимать оба формата и резолвить target path:
- Новый: `variable.{name, storage, group}` → построить путь
- Старый: `save_to: "..."` → как сейчас

## Файлы

- `resources/js/builder/components/editor/config/overrides/InputConfig.vue` — заменить save_to блок
- `resources/js/builder/dto/types.ts` — добавить тип `Variable`
- `resources/js/builder/utils/variableCompiler.ts` — compile / decompile helpers (общие для всех нод этой группы)
- `app/Domains/Flow/Handlers/InputNodeHandler.php` — поддержать новый формат (см. 05)
- `app/Domains/Flow/Validation/InputNodeValidator.php` (если есть) — обновить schema validation

## Acceptance

- Существующие flows с `save_to: "flow.foo"` открываются в редакторе с правильно проставленными `name`, `storage`, `group`.
- Создание новой Input ноды → дефолт `storage: 'contact'`, без группы.
- Сохранение flow → JSON содержит `variable` блок.
- Runtime input flow продолжает работать на обоих форматах.
- Variable picker (задача 06) видит новые имена сразу как Contact-источник или Temporary.

## Risks

- **Двойственная семантика типа**: «Type» в UI совмещает `expected_type` (валидация) и тип хранимого значения. Документировать что это одно и то же на уровне UI; compiler пишет в оба поля если backend пока хочет.

---

## Связано с

- [[00-overview]] — overview storage
- [[02-input]] — спека input ноды
- [[01-variable-storage-editor]] — Variable Storage Editor
- **Group depth**: если legacy save_to = `contact.a.b.c` (depth > 1) — UI должен show error, но не блокировать загрузку. Авто-фикс: при первом save вырезаем глубину >1.
