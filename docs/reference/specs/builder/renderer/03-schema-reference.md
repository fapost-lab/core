# 03 · Schema reference document

> **Статус: РЕАЛИЗОВАНО.** Документ создан: [`docs/reference/builder-config-schema-reference.md`](https://docs.fapost.in/reference/builder-config-schema)
> — покрывает все field-типы, top-level keys, `visible_when`, валидаторы, reserved keys и fluent API.

**Длительность:** ~0.5 дня
**Зависит от:** 01 (sections добавлены), 02 (новые field-типы добавлены) — реально документировать стоит после стабилизации vocabulary
**Блокирует:** —
**Слой:** documentation (markdown)

## Цель

Создать единый reference-документ, который описывает **всю схему `configSchema`** в одном месте: какие поля принимает PHP NodeHandler в `configSchema()`, что каждое поле значит, какое UI-поведение даёт, какие у него валидации.

Сейчас вся информация разбросана:
- Тип `string`/`text`/`enum` — известны из `SchemaConfigRenderer.FIELD_COMPONENTS`.
- Тип `json`/`state-picker` — добавлены в Phase 1, документированы только в коде комментариями.
- Тип `object`/`key-value`/`object-array` — будут в Phase 2 (`02-phase-2-field-types.md`).
- `sections`/`visible_when`/`required`/`label`/`help`/`default`/`placeholder` — частично описаны в задачах 01 и 02 builder-renderer'а.
- Field-level validators (`min`/`max`/`regex`) — Phase 2.

Авторы handler'ов и плагинов (после ADR-06 plugin extensibility) должны иметь **одну точку входа** где видно все возможности — без копания по 5 файлам и коду renderer'а.

## Целевой формат документа

Файл: `docs/reference/builder-config-schema-reference.md`.

### Структура разделов

```
1. Overview — что такое configSchema, кто его читает, как попадает в Vue
2. Top-level keys
   - required: list<string>
   - sections: list<{ key, label, icon, fields, collapsed }>
   - <field_name>: FieldSchema
3. Field types
   3.1 string
   3.2 text
   3.3 number
   3.4 boolean
   3.5 enum
   3.6 array
   3.7 json
   3.8 state-picker
   3.9 object              (Phase 2)
   3.10 key-value          (Phase 2)
   3.11 object-array       (Phase 2)
4. Common field props (label, help, placeholder, default, required)
5. Conditional visibility (visible_when)
6. Field-level validators (min/max/regex)
7. Reserved field keys (required, sections — нельзя использовать как имена fields)
8. Examples per field type — short snippets
9. Migration guide — старые ноды → новый формат
```

### Каждый field type — описание по шаблону

```
### string

Plain single-line text input.

Schema props:
| key         | type    | required | description                              |
|-------------|---------|----------|------------------------------------------|
| type        | 'string'| yes      |                                          |
| label       | string  | no       | Field label shown above input            |
| placeholder | string  | no       | Input placeholder                         |
| default     | string  | no       | Default value if config[key] is undefined |
| help        | string  | no       | Helper text below input                   |
| required    | bool    | no       | Marks field with red asterisk             |
| regex       | string  | no       | Regex pattern (Phase 2)                   |
| regex_message | string| no       | Error message when regex fails            |

Renders: <TextField/> — input type=text with VariablePicker integration.

Example:
```php
'event_type' => [
    'type'        => 'string',
    'label'       => 'Event type',
    'placeholder' => 'sales.order.created',
    'required'    => true,
    'regex'       => '^[a-z][a-z0-9_.]*$',
],
```

UI behavior: VariablePicker `{…}` появляется справа от input'а — клик вставляет `{{...}}` в позицию каретки, drag&drop, copy в clipboard.
```

Аналогично для всех остальных типов.

## Что в нём ДОЛЖНО быть

- **Полный список** field-types и top-level keys (на момент написания).
- **Версионирование** — пометка какие типы доступны с какого момента (Phase 1 / Phase 2).
- **Примеры** для каждого типа — копипастабельный snippet PHP.
- **Renderer behavior** — какой Vue-компонент рендерится, какие интеграции (VariablePicker, autocomplete, validation).
- **Reserved keys** — которые нельзя использовать как field names (`required`, `sections` — meta-ключи).
- **Migration guide** — как старые ноды переводить на новый формат (например, плоские `configSchema` без sections → с sections).

## Что в нём НЕ должно быть

- Описание runtime-семантики node handler (это в `flow-engine-v1/`).
- Engine state model — туда же.
- Plugin extensibility detalies (ADR-06) — отдельный документ.

## Связь с задачами

После завершения 01-visual-polish и 02-phase-2 vocabulary схемы стабилизируется. Reference doc собирает финальную картину. До этого — частичные описания живут внутри 01/02.

Этот reference doc должен быть **обязательным шагом** при добавлении нового field-типа: добавил type → расширил reference. Аналог документации API.

## Файлы

- `docs/reference/builder-config-schema-reference.md` (новый, создаётся в рамках задачи)
- `CLAUDE.md` — добавить ссылку в раздел Flow Engine на reference doc

## Acceptance criteria

- Документ покрывает все типы из текущего FIELD_COMPONENTS map в `SchemaConfigRenderer.vue`.
- Каждый field type имеет полную таблицу props + пример + описание renderer behavior.
- Reserved keys списком, явно перечислены.
- Пример section с иконкой и `collapsed` указан.
- Раздел про migration — старые `'response' => ['type' => 'text']` без `sections` → как переписать (то есть никак, fallback покрывает).

## Out of scope

- Автогенерация документа из PHP-types (типа `php artisan generate:schema-reference`) — overkill для V1, ручное обновление достаточно.
- Interactive playground в админке для тестирования schema → renderer — V1.x.

---

## Связано с

- [[04-fluent-schema-api]] — Fluent API реализация
- [[02-phase-2-field-types]] — field types
- [[00-overview]] — обзор renderer
