# Builder generic renderer — план улучшений

> **Статус (актуализировано 2026-06-05): задачи 01–04 РЕАЛИЗОВАНЫ.**
> Sections с иконками (01), Phase 2 field-типы object/key-value/object-array/visible_when/inline-validators (02),
> reference-doc (03, → [`docs/reference/builder-config-schema-reference.md`](https://docs.fapost.in/reference/builder-config-schema)) и
> fluent API в `fapost/support` (04) — всё в проде. Все 10 Core-handler'ов используют `Schema::make()` + `XxxField::make()`.
> Этот файл сохранён как исторический контекст плана; «Карта задач» ниже больше не backlog.
> Поправка к тексту ниже: Core overrides сейчас — `send_message`, `input`, `condition`/`branch`, `assign`, `call`,
> `set_tag`, `notify`, `auth_request` + vendor glob. `subflow` и `end` сняты с bespoke-override и переведены
> на schema-driven (`flow-picker` / `enum-cards`) в задаче 05 — см. [`05-vendor-glob.md`](05-vendor-glob.md).

Цель: привести правую панель конфигурации для нод **без bespoke override** к качеству, сопоставимому с overrides (SendMessage / Input / Condition / Trigger). Две независимые задачи: визуальное оформление + расширение функциональности схемы.

## Контекст

В Vue builder'е (`resources/js/builder/components/editor/config/`) две стратегии рендера config-формы ноды:

1. **Override** — bespoke Vue-компонент для конкретного типа ноды. Сейчас 4 шт.: `SendMessageConfig`, `InputConfig`, `ConditionConfig`, `TriggerConfig`.
2. **`SchemaConfigRenderer`** — generic. Читает `config_schema` из PHP NodeHandler и рендерит универсальный список input'ов.

`ConfigPanel.vue` маршрутизирует: при выборе ноды смотрит `OVERRIDES[type]` — если есть, отдаёт ей; иначе — generic.

После реализации Storage UI (`docs/reference/specs/builder/storage/`) на generic renderer'е остаются:

- `subflow`
- `rag_query`
- `emit_event`
- `delay`
- `end`
- `call`

Это все ноды кроме SendMessage / Input / Condition / Assign (последняя получит свой override в задачах Storage UI 04).

## Проблема

**Визуально:** generic форма — плоский список input'ов без секций, иконок, помощников. Резко контрастирует с overrides, которые выглядят как полноценные tools (хэдер с иконкой + цветом, секции, контекстные подсказки). Авторам flow это сигнализирует «эта нода — второсортная».

**Функционально:** для сложных конфигов (`call.transport_options`, `rag_query.options`, `result_mapping`) нет адекватных field-типов. Сейчас они либо опускаются в JsonField (плоский JSON-textarea), либо требуют bespoke override.

## Карта задач

| # | Задача | Приоритет | Усилие |
|---|--------|-----------|--------|
| 01 | Visual polish — секции с иконками для generic renderer'а | medium (косметика, но видно сразу) | 0.5–1 день |
| 02 | Phase 2 — новые field-типы (object / key-value / repeater of objects / conditional / validators) | low (когда упрёмся) | 2–3 дня |
| 03 | Schema reference doc — полное описание всех типов схемы и их поведения | medium (после 01+02 — обязательно) | 0.5 дня |
| 04 | Fluent schema API (Filament-style builders) в `fapost/support` | low (после 03 + первого внешнего handler'а) | 1.5–2 дня |

Phase 1 (json/state-picker/help/required/defaults + SelectField fix `options`/`values`) уже сделана.

Задача 03 формализована в `03-schema-reference.md`. Это документация API схемы — обязательное правило при добавлении нового field-типа: расширил `SchemaConfigRenderer` → расширил reference doc.

## Зависимости

- Storage UI задачи 02–04 переведут Input и Assign на свои overrides — после этого набор generic нод стабилизируется.
- Visual polish и Phase 2 независимы между собой и от Storage UI; можно делать в любом порядке.
- Никакого backend-домена не затрагивают (только PHP `configSchema()` definitions + Vue renderer).

## Файлы (общие для обеих задач)

- `app/Domains/Flow/Handlers/{Subflow,RagQuery,EmitEvent,Delay,End,Call}NodeHandler.php` — обновление `configSchema()` (добавление `sections` для polish; новые field-types для Phase 2).
- `resources/js/builder/components/editor/config/SchemaConfigRenderer.vue` — рендер секций / новых field-типов.
- `resources/js/builder/components/editor/config/fields/*.vue` — новые компоненты для Phase 2.
- `resources/js/builder/components/editor/config/AccordionSection.vue` — может потребоваться расширить (icon prop).
- `resources/js/builder/utils/nodeColors.ts` — иконки/цвета per-node, могут переиспользоваться в секциях.

---

## Связано с

- [[01-visual-polish]] — visual polish фаза
- [[02-phase-2-field-types]] — новые field types
- [[04-fluent-schema-api]] — Fluent Schema API
- [[06-frontend-extension-boundary]] — ADR
- [[page-structure]] — структура страниц builder
