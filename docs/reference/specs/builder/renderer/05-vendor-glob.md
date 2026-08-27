# 05 · Generic renderer convergence — flow-picker, retire end/subflow overrides, vendor glob

> **Статус: РЕАЛИЗОВАНО (2026-06-05).** Все четыре части закрыты.
> - **A — `flow-picker`:** `FlowPickerField.vue` (поверх `SearchableSelect`, читает `builderStore.availableFlows`,
>   хранит `flow.id`, `exclude_current` default true) + `Fapost\Support\Builder\Schema\Fields\FlowPickerField`
>   (`excludeCurrent(bool)`). Зарегистрирован в `SchemaFields.vue` `FIELD_COMPONENTS`. Документирован в reference §3.12 + §9.
> - **B — `subflow` без override:** `SubflowNodeHandler::configSchema()` теперь `FlowPickerField` (flow_id) +
>   `SelectField` с 6 ISO-пресетами (timeout, default `PT24H`), Outputs-инфо в `->help()`. `subflow` удалён из
>   `OVERRIDES`, `SubflowConfig.vue` удалён. Сохранённые ноды читаются как есть (формат `flow_id`/`timeout` не менялся).
> - **C — `end` без override (путь C2):** введён field-тип `enum-cards` — `EnumCardsField.vue` +
>   `Fapost\Support\Builder\Schema\Fields\EnumCardsField` (`options([{value,label,icon?,hint?,accent?}])`).
>   `EndNodeHandler` использует его (3 статуса, accent sage/amber/rose, default success). `end` снят с `OVERRIDES`,
>   `EndConfig.vue` удалён. Цвет-кодинг canvas-карты (`FlowNodeCard`) не тронут. Reference §3.13 + §9.
> - **D — vendor glob (ADR-06):** `utils/vendorComponents.ts` (eager `import.meta.glob`,
>   `../../../../vendor/fapost/*/resources/js/builder/*Config.vue` и `*Preview.vue`, строит карты
>   `vendorConfigs`/`vendorPreviews`); чистая утилита маппинга имени `utils/vendorComponentName.ts`
>   (`vendorComponentType()`/`pascalToSnake()`) + unit-тест `vendorComponentName.test.ts` (vitest). `ConfigPanel.vue`
>   резолвит `OVERRIDES[type] ?? vendorConfigs[type] ?? SchemaConfigRenderer` (Core приоритетнее vendor),
>   `FlowNodeCard.vue` — `vendorPreviews[type]` поверх дефолтного summary. Пустой glob → `{}` (build чистый).
>   Маркер «не реализовано» в CLAUDE.md § ADR-06 снят.
>
> Тесты: PHP 341 (Flow + Support) + JS 8 (vitest) зелёные, `vue-tsc` чистый, `vite build` чистый; glob проверен
> временным `vendor/fapost/solution-demo` (попал в бандл, затем удалён). Добавлена devDep `vitest` + script `test` +
> `vitest.config.ts`.
>
> ---
> _Исходный план (исторический контекст, не статус):_
>
> **Статус: НЕ РЕАЛИЗОВАНО.** Заведено 2026-06-05 по итогам аудита покрытия нод + сверки доков с кодом.
> Цель — двигать builder к «generic-by-default»: снять bespoke-overrides, которые схема уже покрывает, добавить
> единственный недостающий field-тип (`flow-picker`) и подключить vendor-glob из ADR-06, чтобы Solution мог
> поставлять свой UI без правки Core.

**Слой:** builder (Vue) + `fapost/support` (новый field) + PHP `configSchema()` · **Зависит от:** — ·
**Блокирует:** первый Solution с собственным config/preview UI (item D)

Порядок: **A → B**, **C**, **D** независимы между собой. A блокирует B.

---

## A. Новый field-тип `flow-picker`

### Зачем
`subflow.flow_id` (и будущие ноды, ссылающиеся на flow — `go_to_flow`) сейчас в generic-схеме это сырой `TextField`
(ввод UUID руками). Override `SubflowConfig` существует только ради searchable-дропдауна по списку flow. Один
переиспользуемый field-тип снимает необходимость в override.

### Что делает
Searchable dropdown по списку доступных flow ассистента, с исключением текущего flow (self-reference). Источник данных —
`builderStore.availableFlows` (`{ id, name }[]`) и `builderStore.flowId` (текущий) — ровно то, что уже использует
[`SubflowConfig.vue:25-32`](../../resources/js/builder/components/editor/config/overrides/SubflowConfig.vue). Значение в
config — `flow.id` (UUID, language-agnostic), а не имя.

### Реализация
- **Vue:** `resources/js/builder/components/editor/config/fields/FlowPickerField.vue` поверх существующего
  [`SearchableSelect.vue`](../../resources/js/builder/components/editor/config/SearchableSelect.vue). Компонент сам
  читает `useBuilderStore()` (как это делают StatePicker/VariablePicker) — список flow приходит не из schema, а из
  runtime-стора. Опция `exclude_current` (default `true`) — прятать текущий flow.
- Зарегистрировать `'flow-picker': FlowPickerField` в `FIELD_COMPONENTS`
  ([`SchemaFields.vue:45`](../../resources/js/builder/components/editor/config/SchemaFields.vue)).
- **PHP:** `packages/fapost-support/src/Builder/Schema/Fields/FlowPickerField.php` (`type() === 'flow-picker'`).
  По аналогии со `StatePickerField` — опционально метод `excludeCurrent(bool)`.
- **Docs:** добавить §3.12 `flow-picker` в
  [`docs/reference/builder-config-schema-reference.md`](../../../builder-config-schema-reference.md) + строку в таблицу §9
  (контракт: новый field-тип → расширил reference в той же PR).

---

## B. Снять `subflow` с override

Зависит от **A**. После flow-picker'а вся форма subflow выражается схемой:

- `flow_id` → `FlowPickerField::make('flow_id')->required()` (вместо текущего `TextField`).
- `timeout` → `SelectField::make('timeout')` с 6 ISO-пресетами из
  [`SubflowConfig.vue:34-41`](../../resources/js/builder/components/editor/config/overrides/SubflowConfig.vue)
  (`PT1H`/`PT6H`/`PT12H`/`PT24H`/`PT48H`/`P7D`), default `PT24H`. Generic `enum` это уже умеет.
- Информационный блок «Outputs» (success/cancelled/failed) → перенести в `->help()` на flow-picker или в `help` секции.
  Это статичный текст, бизнес-логики не несёт.

Затем: удалить `subflow` из `OVERRIDES`
([`ConfigPanel.vue:26`](../../resources/js/builder/components/editor/ConfigPanel.vue)) и сам файл
`SubflowConfig.vue`. Обновить `SubflowNodeHandler::configSchema()`.

---

## C. Снять `end` с override

`EndNodeHandler::configSchema()` уже содержит полный enum статусов (`EndStatus`: success/cancelled/failed, default
success). Override [`EndConfig.vue`](../../resources/js/builder/components/editor/config/overrides/EndConfig.vue) даёт
только radio-карточки с иконкой + подсказкой + цветовым акцентом per-status.

Два пути — выбрать при старте:

- **C1 (минимум, дешёвый):** просто убрать `end` из `OVERRIDES`, удалить `EndConfig.vue` → статус рендерится обычным
  `SelectField`. Дублирование снято, но теряются per-option hints и цветовые карточки в правой панели. Цвет статуса на
  **canvas-карте** ноды (`FlowNodeCard`) при этом не теряется — он живёт отдельно.
- **C2 (рекомендуется, переиспользуемо):** ввести новый field-тип `enum-cards` — radio-карточки с
  `options: [{ value, label, icon?, hint?, accent? }]`. Тогда `end.status` использует его, UX сохраняется, и тип
  переиспользуется (кандидаты: `input.expected_type`, `assign` target и т.п.). Стоимость выше: новый Vue-компонент +
  `EnumCardsField` в support + docs §3.x.

Рекомендация: **C2**, т.к. он не даёт UX-регрессии и единоразово окупается на других нодах. Если приоритет — скорость и
«просто снять дубль», начать с **C1**, `enum-cards` отложить.

---

## D. Vendor config/preview glob (ADR-06)

CLAUDE.md § «Frontend Extension Boundary (ADR-06)» описывает подхват Solution-компонентов через Vite glob как готовый
механизм — в коде его нет: [`ConfigPanel.vue`](../../resources/js/builder/components/editor/ConfigPanel.vue)
маршрутизирует только по хардкод-карте `OVERRIDES`; единственный `import.meta.glob` в builder'е — в `app.ts` для pages.

> Plugin-ноды это **не** затрагивает — любой тип не из `OVERRIDES`/vendor уже рендерится generic-рендерером без
> пересборки. Item D нужен именно для Solution-Vue overrides (с пересборкой).

1. **Config glob** в `ConfigPanel.vue`:
   ```ts
   const vendorConfigs = import.meta.glob('<выверенный путь до>/vendor/fapost/*/resources/js/builder/*Config.vue', { eager: true })
   ```
   Собрать в карту `snake_case type → component` по соглашению `{PascalCaseType}Config.vue → snake_case`
   (`SyncEmployeeConfig.vue → sync_employee`). Резолв: `OVERRIDES[type] ?? vendorConfigs[type] ?? SchemaConfigRenderer`
   (Core имеет приоритет).
2. **Preview glob** для карточек нод (`*Preview.vue → {NodeCard}`). Если per-node preview-override отсутствует в
   `FlowNodeCard`/`FlowSequence`, ввести точку расширения.
3. **Путь glob'а** выверить от `resources/js/builder/components/editor/` до `vendor/fapost/*` в корне — путь в ADR-06
   (`../../vendor/...`) иллюстративный.
4. **Имя→тип** вынести в утилиту `vendorComponentType(filename): string` + unit-тест.
5. Снять пометку «не реализовано» в CLAUDE.md § ADR-06 по закрытию.

---

## Acceptance criteria

- **A:** в generic-схеме поле типа `flow-picker` рендерит searchable-дропдаун по `availableFlows`, исключает текущий
  flow, сохраняет UUID. Документировано в reference §3.x + §9.
- **B:** открываю subflow-ноду → форма из generic-рендерера (flow-picker + timeout-enum), `SubflowConfig.vue` удалён,
  `subflow` нет в `OVERRIDES`. Существующие subflow-ноды в сохранённых flow открываются без потери `flow_id`/`timeout`.
- **C:** `end` рендерится без bespoke-override (C1: SelectField, либо C2: `enum-cards`), `EndConfig.vue` удалён,
  цвет-кодинг canvas-карты не сломан.
- **D:** тестовый `vendor/fapost/solution-demo/resources/js/builder/DemoNodeConfig.vue` → нода `demo_node` рендерит его
  без правок Core; тип без vendor/Core override → generic (регрессии нет); Core приоритетнее vendor; unit-тест на
  маппинг имени.
- Прогон затронутых тестов зелёный (`NodeHandlerSchemaSectionsTest` + новые на field/маппинг). Pint чистый.

## Риски

- **A — источник данных:** field-компонент тянет `builderStore` напрямую (не через schema). Убедиться, что
  `availableFlows` уже загружен к моменту рендера config-панели (как в текущем `SubflowConfig`).
- **B/C — backward compat:** сохранённые ноды содержат `flow_id`/`timeout`/`status` в том же формате — схема читает их
  как есть, миграции данных не требуется. Проверить тестом открытие старого flow.
- **C2 scope creep:** `enum-cards` легко расползается на «ещё и сюда». Ограничить рамками end в этой задаче; остальные
  потребители — отдельно.
- **D — CSP / изоляция:** только статический `import.meta.glob` с `{ eager: true }`, без dynamic runtime import (ADR-06).
  Пустой glob (нет vendor-пакетов) → `{}`, маршрутизация должна это переживать.
- **D:** `npm run build` обязателен после установки Solution — уже зафиксировано (Task 25 / `platform:update`).

---

## Связано с

- [[06-frontend-extension-boundary]] — ADR frontend расширений
- [[04-fluent-schema-api]] — Fluent Schema API
- [[12-solutions-modules]] — Solutions публикуют компоненты
