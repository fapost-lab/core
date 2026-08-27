# Node · `loop` / `loop_end`

**Документ:** Спецификация Loop-ноды для Flow Engine V1 (addendum)
**Версия:** 2.0 — приведена в соответствие с текущей кодовой базой (июнь 2026; исходный addendum — апрель 2026)
**Статус:** см. баннер ниже
**Связанные документы:** `../README.md`, `../01-state-model.md`, `../06-validation.md`, ADR State Writer Semantics, ADR Message Routing & Concurrency Control

> **Статус: ЧАСТИЧНО (актуализировано 2026-06-11).**
>
> ✅ **Сделано (проверено по коду):**
> - **Engine + handlers:** `LoopNodeHandler` (counted/while, structured-left `count_source`/`condition`,
>   литерал-форма `{type: 'literal', value: N}`, init/cleanup итератора),
>   `LoopEndNodeHandler` (инкремент итератора); engine-навигация `loop_end → config.loop_node_id`
>   special-case'ом в `FlowEngine::executeLoop()`. Регистрация в `FlowServiceProvider`.
> - **Отступление от спеки (2026-06-11): total — пер-луп.** Вместо глобального `flow.iteration_total`
>   total counted-режима хранится как `flow.{iterator_name}_total` (`LoopNodeHandler::TOTAL_SUFFIX`),
>   чтобы соседние/последовательные лупы не конфликтовали. Picker и help-текст выводят имя из iterator_name.
> - **iterator_name** денормализуется в config `loop_end` при publish (`PublishFlowService::denormalizeLoopEndIteratorNames`) — вариант (б) из 14.2.
> - **Array type:** `VariableType::Array` (+ попутно `Boolean`), pass-through в `VariableCoercer`;
>   `ContactWriter` — append + circular buffer, `max_size` через lazy schema-registry resolver (раздел 5.2/5.3);
>   session-переменные — append в `AssignNodeHandler::executeOperations` и `InputNodeHandler::persistValue`;
>   media-input в array-переменную аппендит каждый дескриптор отдельным элементом (`persistElements`).
> - **Registry delta:** миграция `tenant_variable_schema.properties jsonb DEFAULT '{}'`,
>   `getProperties()` в `VariableSchemaRegistryInterface` / `CacheBackedVariableSchemaRegistry` (новый формат кеша),
>   properties собираются `VariableSchemaCollector` и персистятся в upsert при publish.
> - **Validation:** 8.2 (`loop_node_id` → существующий loop), 8.3 (формат + reserved `iterator_name`),
>   8.4 (запрет вложенных loop, BFS), 8.8 (literal `count_source` > 100 → publish error).
> - **Builder (отступление от §5.4/5.7 — принято в диалоге 2026-06-11):** вместо `expected_type: "array"` —
>   флаг **«Store as list»** на переменной (`VariableStorageEditor`, компилируется в `type: 'array'` +
>   `properties.item_type`); тип элемента выводится из `expected_type` (семантический маппинг
>   `image→photo`, `document/video/voice/audio→file` — `utils/inputVariableType.ts`); read-only подпись Type
>   в карточке; авто-включение списка для новых input внутри тела цикла. `[]`-суффикс на карточке ноды.
> - **Builder loop UX:** `loop_end` исключён из палитры (`NodeTypesController::AUTO_MANAGED_TYPES`),
>   авто-создаётся при вставке loop, self-heal при загрузке черновика (`builderStore.healLoopConstructs`),
>   удаляется только вместе с loop (`deleteLoop` сносит весь конструкт с confirm); `FlowLoopCard` с кнопкой
>   «Loop body» (вход в ветку `loop`), `default`-цепочка продолжается линейно; bespoke `LoopConfig.vue`
>   (режимы, fixed/variable итерации, while-condition через `ConditionOperandPicker`, advanced `iterator_name`).
> - **Тесты:** `tests/Feature/Domains/Flow/LoopEngineTest.php` (4), `LoopNodeHandlerTest.php` (10); suite зелёный.
>
> ✅ **Добавлено (2026-07-22):**
> - **8.1 — reachability** реализована: `ValidateFlowService::validateLoopReachesLoopEnd` — BFS от `loop`-handle;
>   если ни один путь не достигает `loop_end` с этим `loop_node_id` → error `loop_missing_loop_end` (publish refuse).
>   `loop_end` другого loop не засчитывается (граница чужого тела).
> - **7.2 — конфликт `properties`** теперь **блокирует** publish для array element type: если `item_type` расходится
>   между flow → error `variable_properties_conflict` (`PublishFlowService::assertNoVariableTypeConflicts`).
>   `max_size` сравнению не подлежит — его нет в node config (registry-only, §7.3).
> - **14.3 — `.length` резолвер** для условий: `OperandResolver::resolveWithLength` — `contact.photos.length`
>   даёт count элементов; реальный ключ `length` побеждает pseudo-accessor.
>
> ⏳ **Осталось (TODO):**
> - 8.3 (вторая часть) — запрет session-переменных с именами активных `iterator_name`/`iteration_total`.
> - 8.5 — warning «while condition не меняется в теле» (V1: warning-only, не блокер).
> - 8.7 — static-проверка числового `count_source` (runtime non-number → session failed реализован в handler).
> - 14.1 — решение по iteration budget не принято: действует общий `flow.execution.max_iterations` + правило 8.8;
>   batch-циклы без wait-нод упираются в лимит при ~30 итерациях.
> - 14.3 (вторая часть) — `{{….length}}` в TemplateEngine (шаблоны сообщений) ещё не поддержан; сделано только для условий.
> - UI редактирования `max_size` нет (дефолт 100 из registry) — V1.x, раздел 7.3.

> **Changelog v2.0:** форматы конфигов приведены к фактическим контрактам (structured `left`,
> `expected_type`/`save_to_variable`); Variable Registry сведён к delta поверх существующего
> `tenant_variable_schema`; cleanup итератора — null-конвенция персистера; возврат `loop_end`
> оформлен как engine-level навигация; семантика валидации — `/validate`/Publish; раздел
> Statistics вынесен в отдельный документ [`../../node-usage-statistics.md`](../node-usage-statistics.md).

---

## 1. Обзор

Loop-нода добавляет циклические операции в Flow Engine. Кейсы:

- Серия input с накоплением («загрузите 5 фото»)
- Повторяющиеся операции до условия («спрашивайте, пока не получим валидный ответ»)
- Batch-операции с известным количеством

V1 поддерживает два режима: **counted** (известное N итераций) и **while** (пока условие true).

Документ описывает:

- Две новые ноды: `loop` и `loop_end`
- Новый тип переменной: `array` (`VariableType::Array`)
- Delta к существующему variable schema registry (`properties`)
- Validation rules
- Требуемые доработки движка (раздел 2)

---

## 2. Требуемые доработки кода (сводка)

| Область | Изменение |
|---------|-----------|
| `FlowEngine::executeLoop()` | Special-case навигации `loop_end → config.loop_node_id` (прецедент: `end`, `subflow`); решение по iteration budget (см. 7.8 / 14.1) |
| Handlers | Новые `LoopNodeHandler`, `LoopEndNodeHandler` (Core, graph-unaware) |
| `VariableType` | Новый case `Array = 'array'` |
| `VariableCoercer` | Коэрция array-значений |
| `ContactWriter` | Append-семантика + circular buffer для array-переменных; lookup `max_size` через lazy-resolver schema registry (прецедент: `schemaRegistryResolver` в `VariableResolver`) |
| `tenant_variable_schema` | Миграция: колонка `properties jsonb NOT NULL DEFAULT '{}'` |
| `VariableSchemaCollector` / `PublishFlowService` | Сбор и сравнение `properties` (max_size, item_type) в conflict detection |
| `ValidateFlowService` | Правила 7.1–7.8 |
| `InputNodeHandler` | `expected_type: "array"` + `item_type` |
| `AssignNodeHandler` | Append при записи в array-переменную |
| `SystemStateNamespacePolicy` | Изменений **не требуется** — `flow.*` открыт для всех handler'ов |
| Builder (Vue) | Опция Array + Item type в `VariableStorageEditor`-флоу; конфиг-панели loop/loop_end (schema-driven или override) |

---

## 3. Loop нода

### 3.1 Type и version

- **Type:** `loop`
- **Version:** 1
- **Idempotent:** yes — нода только читает условие и пишет state через `stateChanges`;
  batch персистится атомарно с переходом под optimistic lock

### 3.2 Config

Операнды (`count_source`, `condition.left`) — существующий structured-`left` формат
(`OperandResolver`: `ref: user_variable | source`), операторы — `BranchOperator`.
UI — `ConditionOperandPicker.vue`.

#### Counted режим

```json
{
  "id": "01HQ_loop_node",
  "type": "loop",
  "version": 1,
  "config": {
    "mode": "counted",
    "count_source": {"ref": "source", "source": "contact", "path": "photo_count"},
    "iterator_name": "iterator"
  }
}
```

#### While режим

```json
{
  "id": "01HQ_loop_node",
  "type": "loop",
  "version": 1,
  "config": {
    "mode": "while",
    "condition": {
      "left": {"ref": "source", "source": "flow", "path": "user_finished"},
      "operator": "eq",
      "value": false
    },
    "iterator_name": "iterator"
  }
}
```

### 3.3 Поля

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `mode` | enum | yes | `counted` или `while` |
| `count_source` | structured left | если mode=counted | Источник числа итераций |
| `condition` | structured rule (`left`, `operator`, `value`) | если mode=while | Условие продолжения цикла |
| `iterator_name` | string | no | Имя итератора в session state. Default `iterator`. Alphanumeric + underscore |

### 3.4 Output handles

| Handle | Условие перехода |
|--------|------------------|
| `loop` | Условие итерации выполнено — выполняем тело цикла |
| `default` | Условие не выполнено — пропускаем цикл и идём дальше |

Оба handle — обычные рёбра в `flow_definitions.edges` (`{from, to, handle}`).

### 3.5 Iterator semantics

**1-based индексация.** `flow.{iterator_name}` равен **1** на первой итерации; инкрементируется
в `loop_end` при каждом возврате.

**Counted:**

- Первое достижение Loop: `flow.{iterator_name} = 1`, `flow.iteration_total` = resolved `count_source`
- Условие продолжения: `flow.{iterator_name} <= flow.iteration_total`
- true → `loop` handle, false → `default` handle

**While:**

- Первое достижение Loop: `flow.{iterator_name} = 1`; `flow.iteration_total` не устанавливается
- Условие продолжения: evaluation `condition`
- true → `loop`, false → `default`

**Доступ из expressions** (TemplateEngine):

```
"Сделайте фото #{{flow.iterator}} из {{flow.iteration_total}}"   ← counted
"Вопрос #{{flow.iterator}}"                                       ← any mode
```

### 3.6 Behavior

1. Если `flow.{iterator_name}` равен `null` (не инициализирован) → `stateChanges` устанавливает 1.
   Проверка строго `null === data_get(...)`: персистер не удаляет ключи, очистка = записанный `null`.
2. Resolve условия продолжения:
   - **counted:** evaluate `count_source` → записать в `flow.iteration_total`, проверить `iterator <= total`
   - **while:** evaluate `condition` через `OperandResolver` + `BranchOperator`
3. true → `sourceHandle: "loop"`
4. false → cleanup (записать `null` в `flow.{iterator_name}` и `flow.iteration_total` через
   `stateChanges`) → `sourceHandle: "default"`

**Cleanup при exit** позволяет: переиспользовать `iterator_name` в последовательных циклах
и не загрязнять state мёртвыми значениями.

---

## 4. LoopEnd нода

### 4.1 Type и version

- **Type:** `loop_end`
- **Version:** 1
- **Idempotent:** yes — инкремент итератора в `stateChanges` персистится атомарно с навигацией
  в одной транзакции под optimistic lock; retry не задваивает инкремент

### 4.2 Config

```json
{
  "id": "01HQ_loop_end_node",
  "type": "loop_end",
  "version": 1,
  "config": {
    "loop_node_id": "01HQ_loop_node"
  }
}
```

| Поле | Тип | Required | Описание |
|------|-----|----------|----------|
| `loop_node_id` | string (ULID) | yes | ID parent Loop-ноды, к которой возвращаемся |

### 4.3 Навигация возврата — engine-level

Output handles отсутствуют, рёбер в `edges` нода не имеет. Закон «handlers graph-unaware»
сохраняется так:

- **Handler** (`LoopEndNodeHandler`) читает `iterator_name` из конфига parent Loop-ноды
  (через definition недоступно handler'у — поэтому `iterator_name` резолвит engine, см. ниже)
  и возвращает `executed` + `stateChanges` с инкрементом итератора.
- **Engine** в `executeLoop()` special-case'ом по `LoopEndNodeHandler::TYPE` (прецеденты:
  `EndNodeHandler::TYPE`, `SubflowNodeHandler::TYPE`) выставляет
  `nextNodeId = config.loop_node_id` вместо edge lookup.

Реализационная деталь: чтобы handler остался definition-unaware, engine передаёт
`iterator_name` parent-ноды в node config при подготовке вызова (или handler принимает
инкремент-путь из собственного конфига, денормализованного валидатором при publish —
выбрать при реализации; второй вариант проще: validator копирует `iterator_name` в config
`loop_end` при publish).

Vue Flow может рисовать пунктирную стрелку возврата как visual cue — это не edge в data sense.

### 4.4 Behavior

1. Инкрементировать `flow.{iterator_name}` на 1 (через `stateChanges`)
2. Engine переводит выполнение на `loop_node_id` (повторная проверка условия)

**Failed iteration counts.** Если внутри loop body session failed — failed вся session
(стандартный механизм: handler exception → `FlowEngine::markSessionFailed()`), exit-семантика
цикла не применяется. Итерация считается прошедшей **только если** `loop_end` достигнут.

---

## 5. Array Type

### 5.1 Контекст

Contact attributes сегодня содержат **leaf** (scalar) и **group** (nested object). Loop вводит
третий тип: **array**. Различение через `jsonb_typeof()`:

| Тип | jsonb_typeof | Пример |
|-----|--------------|--------|
| leaf | `string`, `number`, `boolean`, `null` | `"Иван"`, `42` |
| group | `object` | `{"city": "Москва"}` |
| array | `array` | `["url1", "url2"]` |

Код: новый case `VariableType::Array` (существующий `Json` остаётся для structured payload
целиком, например ответа `call`), поддержка в `VariableCoercer`.

### 5.2 Append semantics

Запись в переменную типа array — это **append**, не replace. Реализуется в `ContactWriter`
(сейчас `write()` — строго replace):

```
Initial:           contact.photos = []
After 1st input:   contact.photos = ["url1"]
After 2nd input:   contact.photos = ["url1", "url2"]
```

Поведение действует **всегда** для array-переменной, не только внутри loop:
array = collection, любая запись = добавление.

### 5.3 Circular buffer при достижении лимита

У array-переменной есть `max_size` (см. раздел 7). При append в полный массив:

```
Лимит = 100, текущий размер = 100
Append: shift left на 1 → element[0] удаляется (самый старый), element[99] = новый
```

Фиксированная максимальная память; защита от unbounded роста при ошибках в loop-конструкции.
`max_size` `ContactWriter` получает lookup'ом в schema registry через lazy-resolver
(`ContactWriter` создаётся per-node в `FlowEngine::executeLoop()` — внедрять замыкание,
прецедент `schemaRegistryResolver` в `VariableResolver`).

### 5.4 Input с типом array

`expected_type: "array"` + `item_type` (underlying тип элемента). Target — существующий
`save_to_variable` (Variable shape, `VariableStorage::Session|Contact`):

```json
{
  "type": "input",
  "version": 1,
  "config": {
    "prompt": {"...": "..."},
    "expected_type": "array",
    "item_type": "photo",
    "save_to_variable": {
      "name": "photos",
      "storage": "contact",
      "group": null,
      "type": "array"
    }
  }
}
```

При получении ответа пользователя — append одного элемента в `contact.photos`.

### 5.5 Assign в array

Assign с target array-переменной — тоже append (формат operations — фактический контракт
`AssignNodeHandler`):

```json
{
  "type": "assign",
  "config": {
    "operations": [
      {
        "variable": {"name": "photos", "storage": "contact", "group": null, "type": "array"},
        "value": "{{flow.last_photo_url}}"
      }
    ]
  }
}
```

**Не replace.** Replace массива целиком — отдельная operation (V1.x).

### 5.6 Чтение array

TemplateEngine резолвит пути через `data_get` — индекс пишется dot-синтаксисом:

```
{{contact.photos}}     → весь массив (в тексте — JSON encoded, обычно не нужно)
{{contact.photos.0}}   → первый элемент
{{contact.photos.2}}   → третий элемент
```

Размер массива (`{{contact.photos.length}}`) `data_get` **не поддерживает** — требует
расширения резолвера; вынесено в Open Questions (14.3). Negative indices, slicing — V1.x.

### 5.7 В UI Constructor

Input с array: dropdown `Type` получает опцию «Array», при выборе появляется поле «Item type».
Target — существующий `VariableStorageEditor.vue` (Contact profile / Temporary).

```
┌──────────────────────────────────┐
│ ❓ Ask user                       │
│ Question: "Загрузите фото"       │
│ Save as:                         │
│ ┌────────────────────────────┐   │
│ │ Name: [photos            ] │   │
│ │ Type: [Array     ▾       ] │   │
│ │ Item: [Photo     ▾       ] │   │
│ │ Save to:                   │   │
│ │ ◉ 💾 Contact profile        │   │
│ │ ○ ⏱ Temporary               │   │
│ └────────────────────────────┘   │
└──────────────────────────────────┘
```

`[]`-суффикс обозначает array в node card (`photos[] 💾`).

---

## 6. Loop в графе

### 6.1 Структура

```
                      ┌──────────────┐
                      │  Loop        │
                      │  counted: 5  │
                      └─┬─────────┬──┘
              loop ────┘         └──── default
                │                       │
                ▼                       ▼
          [body node 1]            [next node]
                │
                ▼
          [LoopEnd]
                │
                └─── engine: nextNodeId = loop_node_id ──► Loop (re-evaluate)
```

Рёбра `loop`/`default` — обычные записи в `flow_definitions.edges`; возврат `loop_end → loop` —
engine-навигация без ребра (раздел 4.3).

### 6.2 End внутри loop body

`end` (terminator всего flow) допустим внутри loop body — завершает **весь flow**, не итерацию.
Это workaround для break:

```
Loop:
  ├─ Input photo
  ├─ Branch: photo invalid?
  │     ├─ true → End (status=failed)  ← завершает flow целиком
  │     └─ false → continue
  └─ LoopEnd
```

Полноценный break (выход только из loop с продолжением flow) в V1 не поддерживается.

### 6.3 LoopEnd обязателен

Каждая ветка `loop` должна достижимо вести к `loop_end` (см. правило 8.1).

---

## 7. Variable Schema Registry — delta

Registry **уже реализован** (docs/reference/specs/builder/storage 05/10): таблица `tenant_variable_schema`
(ключ `(storage, group, name)`), `CacheBackedVariableSchemaRegistry` (per-tenant кеш,
`invalidate()` после publish), `VariableSchemaCollector`, lazy upsert и cross-flow conflict
detection в `PublishFlowService` (`VariableTypeConflictException`). Orphan-семантика тоже
реализована: `declared_in_flow_id` nullable FK SET NULL — записи переживают удаление flow.

Для loop требуется **только delta**:

### 7.1 Колонка properties

```sql
ALTER TABLE tenant_variable_schema
    ADD COLUMN properties jsonb NOT NULL DEFAULT '{}';
```

Для array:

```json
{ "max_size": 100, "item_type": "photo" }
```

### 7.2 Properties в collect/conflict

`VariableSchemaCollector` собирает properties из node config; conflict-check в
`PublishFlowService` сравнивает type **и properties**. Ошибка — расширенное сообщение
существующего `VariableTypeConflictException`:

```
Cannot publish flow "Survey Photos":
  Variable contact.photos already registered with properties:
    type: array, max_size: 100, item_type: photo

  This flow tries to use it with:
    type: array, max_size: 50, item_type: photo

  Resolve by:
    - Using max_size=100 in your flow (matching existing)
    - OR using a different variable name
```

### 7.3 max_size — source of truth

Registry, не node config. В JSON snapshot input-ноды `max_size` **не хранится**; runtime
lookup через lazy-resolver (раздел 5.3). Если record исчез runtime (ручное DB intervention) —
engine fails: «variable schema not found».

В V1 — без UI редактирования registry (изменение properties — миграция данных или
drop-recreate переменной при отсутствии данных). V1.x: раздел «Variables» в Admin Panel.

---

## 8. Validation Rules

Дополнения к `../06-validation.md`. Семантика уровней: save draft валидацию **не вызывает**
(закон «Save draft без валидации»); warnings возвращает явный `/validate`, errors — атомарно
при Publish.

### 8.1 LoopEnd обязателен

Для каждой Loop-ноды: BFS/DFS от target ребра `loop`; хотя бы один путь должен достичь
`loop_end` с `loop_node_id`, ссылающимся на эту Loop.

**/validate:** warning «Loop без LoopEnd — итерация не завершится» · **Publish:** error, refuse

### 8.2 LoopEnd ссылается на существующий Loop

`loop_node_id` указывает на существующую Loop-ноду в том же flow_definition.

**/validate:** warning · **Publish:** error

### 8.3 iterator_name

Alphanumeric + underscore; не равен reserved `iteration_total`. Дополнительно: запрет на
объявление user-переменных Session-storage с именами, совпадающими с активными
`iterator_name` / `iteration_total` (enforce в `VariableSchemaCollector`/`ValidateFlowService`).

### 8.4 Запрет вложенных Loop

BFS от `loop` handle; другая Loop-нода в reachable set → nested loop.

**/validate:** warning · **Publish:** error «Nested loops not supported в V1»

### 8.5 While condition должна меняться

Extract paths из `condition.left`; проверить, что хотя бы одна нода тела пишет в них
(input / assign / call с save в эту переменную). Иначе — предупреждение о possible infinite loop.

**/validate:** warning · **Publish:** warning (не блокер: condition может меняться внешне,
например event-триггером другого flow, пишущим в Contact). Strict check — V1.x.

### 8.6 Variable registry conflicts

Publish сверяет все переменные flow с registry (реализовано; delta — properties, раздел 7.2).
Conflicts → refuse.

### 8.7 Counted source must be number

Best-effort static check на publish: literal → проверка типа; `contact.*` с известным
registry-типом → проверка; `flow.*` (runtime computed) → skip. Runtime non-number →
session failed.

### 8.8 Batch-loop iteration budget

Связано с лимитом `flow.execution.max_iterations = 100` (`config/flow.php`): каждая итерация
цикла стоит `body + 2` engine-итерации в одном проходе `executeLoop()`. Циклы с wait-нодами
(`input`) безопасны — итерация выполняется в отдельном resume. **Batch-циклы без wait-нод**
упираются в лимит при ~30 итерациях.

До решения 14.1: counted-loop без wait-нод в теле с литеральным `count_source`, превышающим
бюджет — **Publish: error**; с runtime-значением — **/validate: warning**.

---

## 9. Iterator Cleanup и Sequential Loops

### 9.1 Cleanup при exit

Выход через `default` handle — обнуление через `stateChanges` (null-конвенция персистера,
прецедент: retry counter в `InputNodeHandler`):

```php
$stateChanges['flow.' . $iteratorName] = null;
$stateChanges['flow.iteration_total']  = null;
```

### 9.2 Sequential loops с тем же iterator_name

```
Loop A (iterator_name=iterator) → body A → LoopEnd → Loop A
Loop A exits → flow.iterator = null
Loop B (iterator_name=iterator) → body B → LoopEnd → Loop B
```

Корректно: каждый Loop начинает с 1 (проверка инициализации — `null ===`).

### 9.3 Параллельные loops в разных ветках

Два branch'а, в каждом свой Loop с тем же iterator_name — OK: выполняется только одна ветка.

---

## 10. Failure Modes

| # | Сценарий | Поведение |
|---|----------|-----------|
| 1 | `count_source` = 0 или отрицательное | `1 <= 0` = false → сразу `default`, тело не выполняется. Не error |
| 2 | `count_source` → non-number runtime | Handler exception → session failed (стандартно: `FlowEngine::markSessionFailed()` — failed status, flow_logs, FlowFailed analytics) |
| 3 | While condition throws (напр., DataAccessor module failure) | Session failed, как в №2 |
| 4 | `loop_node_id` → несуществующая Loop runtime (DB intervention; publish-валидация исключает) | Session failed |
| 5 | Massive array fills | Массив держится на `max_size`, старые элементы выпадают. Acceptable by design («accumulate last N items») |
| 6 | Batch-loop превышает iteration budget | `FlowExecutionLimitExceededException`, session failed. См. 8.8 / 14.1 |

---

## 11. Integration с ADR

**ADR State Writer Semantics** — без изменений. Loop опирается на: атомарный batch
`stateChanges` + навигация в одной транзакции под optimistic lock (retry-safe инкремент);
history logging respects `logging_enabled`.

**ADR Message Routing & Concurrency Control** — без изменений. Длинные циклы покрыты
lock heartbeat (`LockHeartbeat` реализован); incoming во время input внутри цикла — стандартный
routing.

**ADR Expression Language** (`../../08-expression-language.md`) — без изменений.
`count_source`/`condition` — structured формы, не выражения; `flow.iterator` /
`flow.iteration_total` доступны через TemplateEngine. `.length`-резолвер — отдельное мелкое
расширение (14.3).

**ADR Subflow Composition** — без изменений. Subflow в теле цикла: каждая итерация = новая
child session (wait-mode; `resumeAfterSubflow` возвращает в post-subflow позицию внутри тела).
Performance: 100 итераций × subflow = 100 child sessions — acceptable, но heavy;
документировать как «осторожно».

---

## 12. V1 Scope

### В V1

- `loop` node type (counted + while)
- `loop_end` node type (engine-level навигация возврата)
- `expected_type: "array"` + `item_type` в input
- Array storage в Contact attributes JSONB; append + circular buffer at max_size
- `tenant_variable_schema.properties` + properties в conflict detection
- `iterator_name` configurable (default `iterator`), `flow.iteration_total` для counted, 1-based
- Validation 8.1–8.8
- End внутри loop body завершает весь flow (workaround break)
- Cleanup итератора при exit (null-присвоение)

### Не в V1

- Break / Continue (отложено до запросов)
- Вложенные loops
- ForEach mode (V1.x — итерация по коллекции с iteration_item)
- Variable registry UI в admin panel (V1.x)
- Array operations beyond append: replace, remove, sort, filter (V1.x)
- Negative indices, slicing в expressions (V1.x)
- Per-flow override variable properties (registry — single source of truth)
- Strict validation для while condition (в V1 только warning)
- Cleanup orphan registry records (V1.x)
- Node usage statistics — вынесено в [`../../node-usage-statistics.md`](../node-usage-statistics.md)

---

## 13. Workflow Examples

### 13.1 Counted Loop с фото

```
1. Ask user "Сколько фото отправите?"  → Number → contact.photo_count (💾)
2. Loop (counted, count_source=contact.photo_count, iterator_name=iterator)
3. [loop]
   3a. Ask user "Загрузите фото #{{flow.iterator}} из {{flow.iteration_total}}"
       → Array (item: photo) → contact.photos (💾)
   3b. LoopEnd (loop_node_id → step 2)
4. [default]
   Send "Спасибо, фото получены"
5. End (success)
```

Результат: `contact.photo_count = 5`, `contact.photos = [url1..url5]`.

### 13.2 While Loop с подтверждением

```
1. Assign: flow.user_done = false (⏱)
2. Loop (while, condition: flow.user_done == false, iterator_name=iterator)
3. [loop]
   3a. Ask user "Введите ответ #{{flow.iterator}} (или /done)"  → Text → contact.responses (💾, array)
   3b. Branch: flow.last_response == "/done"
       - true  → Assign flow.user_done = true (⏱)
       - false → continue
   3c. LoopEnd
4. [default]
   Send "Ответы получены"
5. End
```

### 13.3 Последовательные циклы

```
1. Ask "Сколько фото?" → contact.photo_count (💾)
2. Loop A (counted, iterator_name=iterator) · body: Ask photo → contact.photos · LoopEnd
3. Loop A exits → flow.iterator = null
4. Ask "Сколько вопросов?" → contact.question_count (💾)
5. Loop B (counted, iterator_name=iterator) · body: Ask text → contact.questions · LoopEnd
6. End
```

Переиспользование `flow.iterator` безопасно благодаря cleanup при exit.

---

## 14. Open Questions

1. **Iteration budget (8.8).** Вариант (а): не учитывать `loop`/`loop_end` в `max_iterations`,
   ввести отдельный больший лимит итераций цикла — counted из runtime-значений становится
   рабочим. Вариант (б): оставить общий бюджет + валидация 8.8. Вариант (а) предпочтителен.
   **Решить до реализации.**
2. **Передача `iterator_name` в `loop_end`** (4.3): engine-injection vs денормализация
   validator'ом в config при publish. Второй проще. Решить при реализации.
3. **`{{….length}}` резолвер** — V1 или V1.x?
4. **ForEach mode** — итерация по существующей коллекции. По запросу.
5. **Break/Continue** — формальный механизм. По запросу.
6. **Bulk array operations** — replace, remove, sort, filter. По частям, как понадобятся.
7. **Iterator naming conventions** — рекомендации в документации (`i`, `idx`, `iter`)?

---

## 15. Test Strategy

### Unit

- Loop counted: iteration counts, exit condition, cleanup (null-присвоение)
- Loop while: condition evaluation, cleanup
- LoopEnd: инкремент итератора, engine-возврат к `loop_node_id`
- Array Input: append, circular buffer at max_size
- Schema registry: properties в lazy creation и conflict detection
- 1-based корректность; повторная инициализация после cleanup (`null ===`)

### Integration

- Counted loop, 5 итераций — финальный state
- While loop с user-триггером выхода
- Sequential loops (A → B), переиспользование итератора
- Loop + array input аккумуляция
- End внутри body — завершение всего flow
- Variable conflict на publish (refuse с понятным сообщением)
- Длинный цикл (50+ итераций с input) — lock heartbeat
- Batch-loop на границе iteration budget (поведение по решению 14.1)

### Validation

- Missing LoopEnd → /validate warning, publish error
- Nested loops → /validate warning, publish error
- While condition не меняется в body → warning
- `loop_node_id` → несуществующая нода → error
- iterator_name = reserved → error

### Acceptance Criteria

- [ ] Unit + integration + validation tests pass
- [ ] Manual: counted loop 5 фото end-to-end, `contact.photos` = 5 элементов
- [ ] Manual: while loop выходит при condition=false
- [ ] Manual: sequential loops с одним iterator_name без конфликтов
- [ ] Manual: variable conflict на publish — понятная ошибка
- [ ] Manual: длинный цикл (60 итераций с input) без проблем с lock
- [ ] Performance: 100 итераций × 5 нод — приемлемое время выполнения

---

## 16. Связанные документы

- `../README.md` — индекс спецификации Flow Engine V1
- `../01-state-model.md` — namespaces, contact addressing
- `../06-validation.md` — уровни валидации (draft/publish)
- `../../10-state-writer-semantics.md` — модель state changes
- `../../09-message-routing-concurrency.md` — lock semantics, long-running execution
- `../../08-expression-language.md` — expression syntax
- `../../11-subflow-composition.md` — subflow integration
- `../../storage/05-backend-contract.md`, `../../storage/10-variable-type-coercion.md` — variable contract / schema registry
- `../../node-usage-statistics.md` — node usage statistics (вынесено из этого документа, draft)

---

## Связано с

- [[README]] — nodes README
- [[02-input]] — Store as list флаг на input переменной в loop body
- [[00-overview]] — обзор builder/storage (variable storage)
- [[05-backend-contract]] — backend контракт для array переменных
- [[node-usage-statistics]] — statistics для loop нод
- [[01-state-model]] — state model
- [[10-state-writer-semantics]] — ADR state writer semantics
- [[TASKS]] — задачи реализации
