# 06 · Variable Picker — авто-группировка + иконки источников

**Зависит от:** 02 (Input даёт первые «User variables»), 05 (resolver для read-side)
**Блокирует:** 07 (Branch использует тот же Picker)
**Слой:** builder (Vue)

## Цель

Переделать `VariablePicker` так, чтобы он соответствовал п.6.2–6.3 спеки: переменные сгруппированы по common prefix, рядом с каждой — иконка источника (💾 / ⏱ / 🧠 / ⚡ / 💬 / 🏢). Текущий список плоский и без иконок.

## Текущее состояние

`useFlowVariables()` возвращает hardcoded списки:
- `CONTACT_VARS` — `contact.id`, `contact.language`, `contact.name`, …
- `SYSTEM_VARS` — `system.language`, `system.started_at`, …
- `RAG_VARS` — `rag.answer`, …
- Плюс динамически собранные «flow vars» из set_attribute / input нод (берутся `save_to`).

`VariablePicker.vue` рендерит группы по namespace: `Contact`, `System`, `RAG`, `Flow`. Без иконок, отображает сырые строки `{{flow.code}}`.

## Что меняем

### 1. Новый shape переменной

```ts
interface PickerVariable {
    snippet:  string                 // '{{contact.foo}}' or '{{flow.code}}'
    label:    string                 // user-facing display: 'foo', 'code'
    source:   PickerSource
    group:    string | null          // авто-вычисленная группа из path
    pathSegments: string[]           // для иерархического отображения
}

type PickerSource =
    | { kind: 'contact-profile' }    // 💾
    | { kind: 'temporary' }          // ⏱
    | { kind: 'rag' }                // 🧠
    | { kind: 'api-response' }       // ⚡
    | { kind: 'last-user-message' }  // 💬
    | { kind: 'module', name: string } // 🏢 (HR, etc.)
```

### 2. useFlowVariables — расширение

Источники переменных:
- **Contact profile (💾)** — известные built-in поля (`contact.name`, `contact.phone`, …) + переменные из Input/Assign с `storage='contact'`.
- **Temporary (⏱)** — переменные из Input/Assign с `storage='session'`.
- **RAG (🧠)** — из rag_query нод.
- **API response (⚡)** — из call нод (когда у них настроен `save_response_to`).
- **Last user message (💬)** — статика, всегда доступна.
- **Module (🏢)** — позднее, через registered `DataAccessor`'ы.

Существующие хардкоды CONTACT_VARS / SYSTEM_VARS / RAG_VARS либо переезжают в platform-fixed список, либо превращаются в реализации источников.

### 3. UI — авто-группировка

```
┌──────────────────────────────────────┐
│ 💾 Contact profile                    │
│   contact.name              copy     │
│   contact.phone             copy     │
│   ─── survey_q1 ───                  │
│   contact.survey_q1.score   copy     │
│   contact.survey_q1.comment copy     │
│                                      │
│ ⏱ Temporary                          │
│   flow.code                 copy     │
│                                      │
│ 🧠 RAG                                │
│   rag.answer                copy     │
│                                      │
│ ⚡ API response                       │
│   call.last.status          copy     │
└──────────────────────────────────────┘
```

Группы по prefix внутри Contact источника собираются автоматически из path: `contact.survey_q1.x` → секция `survey_q1`. Группа отрисовывается только если в неё попадает ≥2 переменных или ≥1 кастомная (созданная через UI).

### 4. Поиск (V1.x — opt-in)

Пока без поиска. Когда переменных станет много — добавить input наверху (раздел 12 спеки).

### 5. Behaviors остаются

Picker сохраняет три действия: copy + drag + emit `select` (введено в текущей сессии). Единственное изменение — структура списка.

## Файлы

- `resources/js/builder/composables/useFlowVariables.ts` — расширить источники, вернуть `PickerVariable[]`
- `resources/js/builder/components/editor/config/VariablePicker.vue` — рендер групп с иконками
- `resources/js/builder/utils/variableGrouping.ts` — helper, собирает структуру по common prefix

## Acceptance

- Создаю Input с `storage=contact, group=survey, name=score` → picker сразу показывает `contact.survey.score` в секции 💾 Contact profile / survey.
- Меняю на `storage=session` → переменная переезжает в секцию ⏱ Temporary.
- При drag/click из любой секции — рабочая вставка/копирование как сейчас.
- Старые «flow.*» переменные из legacy snapshot отображаются в Temporary.

## Out of scope

- Search input (V1.x)

---

## Связано с

- [[00-overview]] — overview storage
- [[01-variable-storage-editor]] — Variable Storage Editor
- [[07-branch-source-picker]] — branch source picker
- Module sources (требует registered DataAccessor — отдельная задача)
