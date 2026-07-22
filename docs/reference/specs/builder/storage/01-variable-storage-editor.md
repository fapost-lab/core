# 01 · VariableStorageEditor — общий компонент

**Зависит от:** —
**Блокирует:** 02, 03, 04, 06, 07
**Слой:** builder (Vue)

## Цель

Один Vue-компонент, который инкапсулирует все четыре поля «переменная пользователя»: Name, Type, Save to, Group. Переиспользуется в Input, SendMessage save_to, Assign — везде где нода сохраняет данные пользователя.

Компонент отвечает только за UI и состояние. Compilation в JSON snapshot — забота родительской ноды (см. задачи 02–04).

## API компонента

```ts
interface Variable {
    name:    string                      // alphanumeric + underscore
    type:    'text' | 'number' | 'phone' | 'email' | 'contact'
             | 'select' | 'confirm' | 'file' | 'photo' | 'location' | 'date'
    storage: 'contact' | 'session'       // default: 'contact'
    group:   string | null               // null = root
}

defineProps<{
    modelValue: Variable | null
    // Узкий список типов, релевантных конкретному контексту. Например, в
    // SendMessage save_to ожидаются только string|number|boolean — отдадим
    // только их.
    typeOptions?: Array<{ value: string; label: string }>
    // Список существующих групп для dropdown (раздел 4.3). Источник —
    // builder store, собирает имена групп из других нод.
    knownGroups?: string[]
    // Управляет видимостью поля Group. По умолчанию true — но в SendMessage
    // (там это лишний UI) можно скрыть.
    showGroup?: boolean
    // Управляет видимостью переключателя Save to. По умолчанию true; для
    // нод где режим хранения предопределён (например, всегда temporary)
    // можно скрыть и зафиксировать значение.
    showStorage?: boolean
}>()

defineEmits<{
    (e: 'update:modelValue', value: Variable): void
}>()
```

## UI макет

Соответствует разделу 3.1 спецификации:

```
┌────────────────────────────────────┐
│ Save as:                            │
│ ┌────────────────────────────────┐ │
│ │ Name:    [code             ]   │ │
│ │ Type:    [Number       ▾   ]   │ │
│ │                                │ │
│ │ Save to:                       │ │
│ │ ◉ 💾 Contact profile            │ │
│ │ ○ ⏱ Temporary                   │ │
│ │                                │ │
│ │ Group:   [— ▾]                 │ │
│ └────────────────────────────────┘ │
└────────────────────────────────────┘
```

### Поведение Group dropdown

```
[▾]
  (No group)               ← default
  ─────
  survey_q1                ← existing groups
  address
  ─────
  + Create new group...    ← inline prompt
```

`+ Create new group` открывает inline текстовое поле в том же месте, без модалки. Submit — добавляет группу в `knownGroups` (через emit) и выбирает её.

Поле Group видно **только** при `storage === 'contact'` (Temporary не группируется).

## Валидация (inline)

| Поле | Правило | Сообщение |
|------|---------|-----------|
| Name | alphanumeric + underscore | `Use letters, digits, underscore only` |
| Name | не пустое | `Name is required` |
| Name | не содержит точку | `Group depth is limited to 1 level` (см. п.4.4) |
| Name | не из reserved keys (`id`, `channel_id`, `meta`, …) | `Name is reserved` |
| Group | не `meta` (зарезервировано, п.4.6) | `Group "meta" is reserved` |

Валидация показывается inline под полем; не блокирует emit (parent сам решает что делать с невалидным состоянием).

## Файлы

- `resources/js/builder/components/editor/variables/VariableStorageEditor.vue` — основной компонент
- `resources/js/builder/components/editor/variables/StorageRadio.vue` — sub-component для radio (Profile / Temporary) с иконками
- `resources/js/builder/components/editor/variables/GroupSelect.vue` — sub-component для group dropdown с inline create
- `resources/js/builder/composables/useKnownGroups.ts` — собирает `knownGroups` из всех Input/Assign нод текущего flow для autocomplete

## Тестирование

Компонентные тесты пока без инфраструктуры (билдер не покрыт unit-ами). Smoke в storybook-style preview-странице (`pages/preview/VariableStorageEditorPreview.vue`) — рендерит несколько вариантов с разными `typeOptions`/`knownGroups`.

Acceptance:
- Все варианты props (с/без Group, с/без Storage, с разными Type lists) рендерятся корректно.
- `+ Create new group` добавляет группу и переключает выбор.
- Изменение Storage с `contact` на `session` скрывает Group и в emit'е приходит `group: null`.
- Validation messages появляются и пропадают синхронно с input.

## Out of scope

- Drag-and-drop reordering групп (раздел 12 спеки)
- Tooltip с примерами (раздел 12)

---

## Связано с

- [[00-overview]] — overview storage
- [[02-input-node-migration]] — миграция input ноды
- [[05-backend-contract]] — backend контракт
- Bulk rename переменных (V1.x backlog)
