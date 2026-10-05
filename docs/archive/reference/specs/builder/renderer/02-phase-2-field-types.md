# 02 · Schema renderer — Phase 2 (новые field-типы)

> **Статус: РЕАЛИЗОВАНО.** `ObjectField`/`KeyValueField`/`ObjectArrayField` есть и в `fapost/support`, и в
> `resources/js/builder/components/editor/config/fields/`; `visible_when` резолвится через
> `composables/useFieldVisibility.ts` (операторы `equals`/`in`/`truthy`); inline-валидаторы (`regex`, `min`/`max`) — в
> `TextField.vue`. `CallNodeHandler` уже строит схему на `ObjectField`+`ObjectArrayField`. Документировано в
> [`docs/reference/builder-config-schema-reference.md`](https://docs.fapost.in/reference/builder-config-schema) §3.9–3.11, §5–§6.

**Длительность:** 2–3 дня
**Зависит от:** Phase 1 (выполнена), 01-visual-polish (рекомендуется но не строго)
**Блокирует:** —
**Слой:** builder (Vue) + PHP `configSchema()` updates где применимо

## Цель

Добавить функциональные расширения generic renderer'а: nested objects, key-value maps, repeater of objects (sub-schema), conditional visibility, field-level validators. Это закроет «потолок» schema-driven UI для сложных конфигов (`call.transport_options`, HTTP headers, RAG `result_mapping`, и т.п.) — без необходимости писать bespoke override на каждую такую ноду.

## Текущий гэп

Конкретные кейсы где Phase 1 не хватает:

| Конфиг | Что нужно | Сейчас |
|--------|-----------|--------|
| HTTP headers (call) | `Record<string, string>` | свободный JSON через JsonField |
| `call.transport_options` | nested object с под-полями (timeout, retries, success_when) | плоский JSON |
| `result_mapping` (call) | массив объектов `{from_path, to_state_key, transform}` | JSON блоб |
| `rag_query.options` | provider-specific nested options | JSON |
| `emit_event.payload` | ad-hoc JSON | JsonField (приемлемо, но без структуры) |
| `success_statuses` (call) | показать только если `success_when === 'custom'` | всегда видно |
| Validation `min/max/regex` | inline UX-feedback в форме | только бэкенд при save |

## Что добавляется

### 1. `object` field — nested group

Schema:
```php
'transport_options' => [
    'type'   => 'object',
    'label'  => 'Transport options',
    'help'   => 'HTTP delivery configuration.',
    'fields' => [
        'timeout' => ['type' => 'number', 'label' => 'Timeout (s)', 'default' => 10],
        'retries' => ['type' => 'number', 'label' => 'Retries', 'default' => 0],
        'success_when' => [
            'type'    => 'enum',
            'label'   => 'Success when',
            'options' => ['2xx', 'any_response', 'custom'],
            'default' => '2xx',
        ],
    ],
],
```

Render: вложенная карточка / inset-блок. State path — `config.transport_options.{nested-key}`.

Реализация: новый `ObjectField.vue` рекурсивно вызывает `SchemaConfigRenderer` для своих `fields` со scoped state path. Renderer должен принимать `pathPrefix` prop для корректной адресации nested'ого state'а.

### 2. `key-value` field — Record<string, string>

Schema:
```php
'headers' => [
    'type'        => 'key-value',
    'label'       => 'Headers',
    'placeholder' => ['Authorization' => 'Bearer ...'],
    'key_label'   => 'Header',
    'value_label' => 'Value',
],
```

Render: список парных input'ов (key, value) + кнопка «+ Add». Удаление через ✕ справа. Дубликаты ключей подсвечиваются как ошибка.

Реализация: `KeyValueField.vue` — Vue-аналог Filament KeyValue. State — `Record<string, string>`. Сохранение пустых ключей не происходит.

### 3. `object-array` field — repeater of objects

Schema:
```php
'result_mapping' => [
    'type'  => 'object-array',
    'label' => 'Result mapping',
    'help'  => 'Map response paths to flow state keys.',
    'item'  => [
        'fields' => [
            'from_path' => ['type' => 'state-picker', 'label' => 'From response'],
            'to_state'  => ['type' => 'state-picker', 'label' => 'To state'],
            'transform' => [
                'type'    => 'enum',
                'label'   => 'Transform',
                'options' => ['none', 'cast_to_string', 'cast_to_int'],
                'default' => 'none',
            ],
        ],
        'item_label' => '{from_path} → {to_state}',  // template для аккордеона
    ],
    'min_items' => 0,
    'max_items' => 50,
],
```

Render: collapsible repeater (как Filament Repeater). Каждый item → `SchemaConfigRenderer` под item.fields.

Реализация: `ObjectArrayField.vue` — extends текущий `ArrayField`, но item — не string а объект.

### 4. Conditional visibility — `visible_when`

Schema:
```php
'success_statuses' => [
    'type'  => 'array',
    'label' => 'Custom success statuses',
    'visible_when' => ['transport_options.success_when' => 'custom'],
],
```

`visible_when` — словарь pathInForm → expectedValue. Поле отображается только если **все** условия true. Поддержка nested path через dot-notation.

Реализация: в `SchemaConfigRenderer` перед рендером поля резолвить `visible_when` против текущего config state. Если condition не выполнен — поле не рендерится (значение в state остаётся, не очищается — пользователь может переключить toggle обратно и вернуть данные).

Возможные операторы (V1 — только equality, расширение — V1.x):
- `equals` (default): значение == expected
- `in`: значение ∈ expected (когда expected — массив)
- `truthy`: значение truthy

Синтаксис расширенный:
```php
'visible_when' => [
    ['field' => 'transport_options.success_when', 'op' => 'equals', 'value' => 'custom'],
],
```

Если только equality — короткий синтаксис `['path' => 'value']` достаточен.

### 5. Field-level validators

Schema:
```php
'timeout' => [
    'type'    => 'number',
    'label'   => 'Timeout (s)',
    'min'     => 1,
    'max'     => 300,
    'default' => 10,
],
'event_type' => [
    'type'  => 'string',
    'label' => 'Event type',
    'regex' => '^[a-z][a-z0-9_.]*$',
    'regex_message' => 'lowercase letters, digits, underscores, dots',
],
```

UI: inline error display под полем во время ввода (debounced). Не блокирует save (валидация на бэкенде остаётся источником истины), но показывает feedback раньше.

Реализация: каждый field-component получает `schema` props и сам решает применять ли validators (TextField проверяет regex, NumberField проверяет min/max). Сообщение — через computed property + render под input'ом.

## Файлы

Новые:
- `resources/js/builder/components/editor/config/fields/ObjectField.vue`
- `resources/js/builder/components/editor/config/fields/KeyValueField.vue`
- `resources/js/builder/components/editor/config/fields/ObjectArrayField.vue`
- `resources/js/builder/composables/useFieldVisibility.ts` — резолвинг `visible_when`

Модификации:
- `resources/js/builder/components/editor/config/SchemaConfigRenderer.vue` — добавить новые типы в `FIELD_COMPONENTS`, передавать `pathPrefix` для nested object-полей, фильтровать поля по `visible_when`
- `resources/js/builder/components/editor/config/fields/TextField.vue` — добавить regex валидацию + inline error
- `resources/js/builder/components/editor/config/fields/NumberField.vue` (если выделен; иначе TextField type=number) — min/max
- `app/Domains/Flow/Handlers/CallNodeHandler.php` — пример: переписать `transport_options` на `object` + `headers` на `key-value` + `result_mapping` на `object-array` + `success_statuses` с `visible_when`
- `app/Domains/Flow/Handlers/RagQueryNodeHandler.php` — `options` → `object` если provider-specific shape известна
- `app/Domains/Flow/Validation/FlowDefinitionValidator.php` — schema-shape валидация (object schema correct, visible_when path резолвится, validators не противоречивые)

## Acceptance criteria

- `object` field: открываю call-ноду → секция «Transport options» с inset-блоком, внутри 3 nested input'а. State сохраняется как `transport_options: { timeout: ..., retries: ... }`.
- `key-value` field: добавление/удаление пары работает, дубликаты ключей подсвечиваются.
- `object-array` field: добавление item'а через `+ Add`, collapsible с `item_label` template.
- `visible_when`: переключение `success_when` с `2xx` на `custom` показывает поле `success_statuses`; при возврате — поле снова скрывается, но значение в state не пропадает.
- regex validator: ввод невалидного `event_type` → inline-сообщение под полем; всё ещё можно сохранить (бэкенд отвергнет).
- min/max validator: number input помечается ошибкой если значение вне диапазона; всё ещё можно сохранить.

## Риски

- **Recursion infinite loop в ObjectField** — если schema объекта рекурсивно ссылается сама на себя. Защита: depth-guard в renderer'е (max 5 уровней).
- **`visible_when` производительность** — на каждом рендере резолвить условия. Для больших форм с 20+ полями может быть заметно. Оптимизация: computed property + memoization по relevant config-keys.
- **Backward compat** — старые `configSchema` без новых типов продолжают работать. Тесты должны покрывать что fallback (string/number/text/enum/...) рендерится без проблем когда `object`/`visible_when` отсутствуют.
- **State leak при невидимом поле** — при сохранении flow в JSON значения скрытых полей попадают в snapshot и могут запутать читателя/runtime. Решение: документировать поведение (значение остаётся, но при validate runtime либо игнорирует если parent hidden, либо обрабатывает) — НЕ автоматически очищать при `visible_when=false`.

## Out of scope

- Cross-field validators (e.g., `field A must be > field B`) — V1.x.
- Async validation (e.g., проверить уникальность event_type на бэкенде) — V1.x.
- Drag-reorder items в object-array — V1.x.
- Conditional schema (поле меняет тип в зависимости от другого поля) — слишком сложный edge case, не нужен.

---

## Связано с

- [[00-overview]] — обзор renderer
- [[03-schema-reference]] — schema reference
- [[04-fluent-schema-api]] — Fluent API
