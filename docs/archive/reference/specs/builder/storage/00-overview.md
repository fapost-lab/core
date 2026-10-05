# Storage UI — Implementation Plan

> **Статус реализации:** см. [[../../../TASKS]]. `CLAUDE.md` содержит только правила для агентов и архитектурные ограничения.
> → Variable storage. Замечание по задаче 11: dedicated `EndConfig.vue` был заменён на schema-driven `enum-cards`
> (см. `../builder-renderer/05-vendor-glob.md`); `EndConfig.vue` удалён, на canvas осталось только color-coding по `status`.

Разбиение спецификации `flow-constructor-ui-data-storage.md` на пригодные к раздаче задачи.

## Принципы

- Каждая задача — самостоятельный PR со своим acceptance criteria.
- Задачи упорядочены по зависимостям: 01→02→03→04 фронт, 05 бэкенд параллельно с 02–04, 06–09 — после.
- Реализуем итеративно: после 01+02 уже видна первая нода с правильным UX, всё остальное можно вкатывать постепенно без поломки flows.

## Карта задач

| # | Задача | Зависит от | Слой |
|---|--------|------------|------|
| 01 | `VariableStorageEditor.vue` — общий компонент Name + Type + Save to + Group | — | builder (Vue) |
| 02 | Миграция Input node на VariableStorageEditor | 01 | builder + backend resolver |
| 03 | Миграция SendMessage `save_to` (кнопки) на VariableStorageEditor | 01, 02 | builder + handler |
| 04 | Миграция Assign node на VariableStorageEditor | 01, 02 | builder + handler |
| 05 | Backend variable contract + backward-compat resolver | — (можно параллельно 02) | backend (PHP) |
| 06 | Variable Picker — auto-grouping + source icons | 02, 05 | builder |
| 07 | Branch — dropdown переменных + source picker | 02, 06 | builder |
| 08 | Contact card — отображение групп секциями | 05 | filament admin |
| 09 | Flow `logging_enabled` flag + per-session history | — | backend + filament |
| 10 | Variable type coercion + per-tenant schema registry | 02, 04, 05 | backend + filament |
| 11 | End node — dedicated config panel + canvas styling | — | builder (Vue) |

## Состояние «спрятанного» в UI

Спецификация явно запрещает (см. п.1.2) показывать пользователю термины `namespace`, `session`, `scope`, `flow.*`, `contact.*`. Все эти концепты должны жить только в JSON snapshot и runtime engine. UI оперирует терминами «Profile / Temporary», «Group», «имя переменной».

## Backward compatibility

JSON snapshot для существующих flows может содержать legacy формы:
- Input: `save_to: "flow.foo"` или просто `save_to: "foo"`
- SendMessage button save_to: `save_to: "menu_choice"` (всегда `flow.*`)
- Assign: `target: "flow"|"contact"`, `key: "..."`, `value: "..."`

Резолвер на бэкенде поддерживает обе формы — задача 05 описывает миграцию контракта.

## Связанные документы

- `flow-constructor-ui-data-storage.md` — UX-спецификация (источник истины)
- `../flow-engine-v1/` — engine internals
- `../builder-renderer/03-schema-reference.md` — reference документ всех field-типов схемы (общий для Storage UI overrides и generic renderer'а; обязателен для понимания contract'а PHP↔Vue)
- `CLAUDE.md` § Multilingual / Flow Engine

---

## Связано с

- [[01-variable-storage-editor]] — Variable Storage Editor
- [[05-backend-contract]] — backend контракт
- [[11-loop]] — loop нода использует array variables
- [[02-input]] — input нода с variable storage
