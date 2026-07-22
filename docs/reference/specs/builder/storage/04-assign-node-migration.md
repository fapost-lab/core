# 04 · Assign node — миграция на VariableStorageEditor

**Зависит от:** 01, 02
**Блокирует:** —
**Слой:** builder (Vue) + handler (PHP)

## Цель

Текущий Assign config: `target` (enum `flow|contact`) + `key` + `value`. Это прямое отображение namespace на UI — нарушает п.1.2 спеки. Заменить на `VariableStorageEditor` (Name + Type + Save to + Group) + поле Value.

Ноду можно сделать массивной — один Assign содержит N операций (раздел 3.2 спеки). В рамках этой задачи поддержим массив сразу — иначе придётся через V1.x.

## Текущее состояние

`AssignNodeHandler::configSchema()`:
```php
[
    'target' => ['type' => 'enum', 'options' => ['flow', 'contact'], 'required' => true],
    'key'    => ['type' => 'string', 'placeholder' => 'language', 'required' => true],
    'value'  => ['type' => 'text', 'placeholder' => '{{flow.input}}', 'required' => true],
]
```

UI рендерит через `SchemaConfigRenderer` (нет dedicated override).

`AssignNodeHandler::execute()` пишет одно значение через ScopedStateWriter в путь `{target}.{key}`.

## Что меняем

### 1. Создать override `AssignConfig.vue`

Компонент содержит Repeater по операциям. Каждая операция:

```vue
<div class="assign-op">
    <VariableStorageEditor
        v-model="op.variable"
        :type-options="ASSIGN_TYPES"
        :known-groups="knownGroups"
        show-storage
        show-group
    />
    <TextareaField
        v-model="op.value"
        :schema="{ label: 'Value', placeholder: '{{flow.input}}' }"
    />
</div>
<button @click="addOperation">+ Add operation</button>
```

`ASSIGN_TYPES`:
```ts
const ASSIGN_TYPES = [
    { value: 'text',    label: 'Text' },
    { value: 'number',  label: 'Number' },
    { value: 'confirm', label: 'Yes/No' },
    { value: 'date',    label: 'Date' },
]
```

### 2. Compilation

UI → snapshot:
```json
{
    "type": "assign",
    "config": {
        "operations": [
            {
                "variable": { "name": "full_name", "type": "text", "storage": "contact", "group": null },
                "value": "{{flow.first_name}} {{flow.last_name}}"
            },
            {
                "variable": { "name": "code", "type": "number", "storage": "session", "group": null },
                "value": "{{flow.entered_code}}"
            }
        ]
    }
}
```

### 3. Decompilation legacy

Старая Assign со схемой `{ target, key, value }` → одна операция:
```ts
{
    variable: {
        name:    config.key,
        type:    'text',
        storage: config.target === 'contact' ? 'contact' : 'session',
        group:   null,
    },
    value: config.value,
}
```

### 4. Backend

`AssignNodeHandler::execute`:
- Если есть `operations` массив → итерировать по нему, резолвить каждый target path, писать через ScopedStateWriter.
- Иначе legacy: использовать одиночный target+key+value.

```php
$operations = $config['operations'] ?? null;

if (is_array($operations)) {
    foreach ($operations as $op) {
        $path = $variableResolver->resolveTargetPath($op['variable']);
        $writer->write($path, $renderer->render($op['value']));
    }
} else {
    // legacy single-op path
    $writer->write("{$config['target']}.{$config['key']}", $renderer->render($config['value']));
}
```

### 5. Validation

Per operation:
- variable.name — required, alphanumeric + underscore
- value — required (templates допустимы)
- Не разрешать дублирующиеся (storage, group, name) пары внутри одного Assign — это явная ошибка.

## Файлы

- `resources/js/builder/components/editor/config/overrides/AssignConfig.vue` — новый override
- `resources/js/builder/components/editor/ConfigPanel.vue` — добавить `assign: AssignConfig` в OVERRIDES
- `app/Domains/Flow/Handlers/AssignNodeHandler.php` — поддержать operations + legacy
- `app/Domains/Flow/Validation/FlowDefinitionValidator.php` — расширить validation если нужно

## Acceptance

- Открываю legacy assign `{target: contact, key: name, value: ...}` → один operation editor с правильно проставленными полями.
- Кнопка `+ Add operation` добавляет новую пустую переменную.
- Сохранение перезаписывает в новую `operations` форму.
- Runtime: множественный assign последовательно пишет в state. Read-after-write: в operation 2 виден pending write из operation 1 (см. ADR State Writer Semantics).

## Risks

- Read-after-write semantic в одном assign требует чтобы все writes шли через единый ScopedStateWriter — уже так в текущей архитектуре.

---

## Связано с

- [[00-overview]] — overview storage
- [[05-assign]] — спека assign ноды
- [[05-backend-contract]] — backend контракт
- При наличии операций с одинаковым target path — последняя выигрывает, но валидация должна предупреждать.
