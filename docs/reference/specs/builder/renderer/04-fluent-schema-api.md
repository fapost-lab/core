# 04 · Fluent schema API (Filament-style builders)

> **Статус: РЕАЛИЗОВАНО.** Namespace зафиксирован как `Fapost\Support\Builder\Schema` (`packages/fapost-support/`),
> Phase 1 **и** Phase 2 vocabulary в проде, все 10 Core-handler'ов мигрированы на fluent.
> **Поправка к примерам ниже:** фактический API — `XxxField::make($name)` (`TextField::make()`, `NumberField::make()`,
> …), а не `Field::string()` / `Number::make()`. У `Schema` также есть `defaultConfig()`. Канон — в
> [`docs/reference/builder-config-schema-reference.md`](../../../builder-config-schema-reference.md) §9.

**Длительность:** ~1.5–2 дня
**Зависит от:** 03 (Schema reference) — vocabulary должен быть зафиксирован до того, как мы оборачиваем его в типизированный API
**Блокирует:** —
**Слой:** `fapost/support` (новый namespace `Fapost\Support\Builder\Schema` или близкий) + миграция `configSchema()` в Core handler'ах

## Цель

Заменить ручную сборку ассоциативных массивов в `NodeHandler::configSchema()` на декларативный fluent API в духе Filament forms:

```php
public function configSchema(): array
{
    return Schema::make()
        ->section('Timing', icon: 'clock')
        ->fields([
            Number::make('seconds')
                ->label('Delay (seconds)')
                ->default(60)
                ->min(1),
        ])
        ->toArray();
}
```

Wire-формат (структура массива, который потребляет `SchemaConfigRenderer.vue`, и JSON в БД для значений конфига) **не меняется** — `toArray()` отдаёт ту же форму, что и сейчас. Это чисто эргономика авторинга.

## Мотивация

Сейчас `configSchema()` — ассоциативный массив с магическими ключами:

```php
return [
    'seconds' => [
        'type'     => 'number',
        'label'    => 'Delay (seconds)',
        'required' => false,
        'default'  => 60,
    ],
];
```

Проблемы:

- Нет автокомплита и refactor-safety: опечатка в `'requried'` ловится только в runtime / в Vue.
- Автор handler'а должен помнить весь словарь полей и их свойств (`type`, `options` vs `values`, `visible_when` shape, validators shape).
- Vocabulary будет расти после Phase 2 (object / key-value / object-array / `visible_when` / inline validators) — без типизации поверх него цена ошибки растёт линейно.
- Solutions/Plugins будут писать свои handler'ы — им нужна устойчивая, дружелюбная сигнатура, а не «копируй массив из Core».

## Решение

### Архитектура

Тонкий fluent layer, который при `toArray()` сериализует в существующий контракт. Внутри билдеров — readonly DTO (PHP 8.4 readonly classes), снаружи — chainable API.

Минимальный набор (Phase 1 vocabulary):

```
Schema           — корневой builder. sections(), fields(), toArray()
Section          — section('Title', icon: ?, collapsible: ?, defaultExpanded: ?)
Field (abstract) — name, label, help, required, default, placeholder, visibleWhen
  ├ Text         — type=text
  ├ Textarea     — type=textarea
  ├ Number       — type=number, min, max, step
  ├ Select       — type=select, options(array|enum), multiple
  ├ Toggle       — type=boolean
  ├ Json         — type=json
  ├ StatePicker  — type=state-picker, namespaces(array)
```

Phase 2 vocabulary (добавляется когда `02-phase-2-field-types.md` стабилизируется):

```
Object         — вложенный объект с собственным fields()
KeyValue       — произвольные пары
ObjectArray    — repeater из объектов
Validator      — Validator::regex(...), Validator::min(...) — chainable на любом Field
```

### Контракт `toArray()`

Должен **байт-в-байт** соответствовать тому, что сейчас потребляет `SchemaConfigRenderer.vue`. Это даёт:

- Нулевую миграцию: Vue renderer не трогается.
- Возможность мигрировать handler'ы по одному, без big-bang.
- Бесплатный fallback: автор может вернуть голый массив, и это продолжит работать.

`SchemaContractTest` (PHPUnit) — golden test, который для каждого Core handler'а проверяет, что `Schema::...->toArray()` эквивалентен старому ручному массиву. Только потом старый массив удаляется.

### Размещение — `fapost/support`

Подходит по критериям из CLAUDE.md § fapost/support:

- Нет зависимостей от Core (`App\*`).
- Переиспользуется Core + Solutions + Plugins.
- Чистый примитив — DTO + builder, без runtime-логики.
- Уже есть как минимум два потребителя (Core handlers + будущие Solution handlers), не преждевременная абстракция.

Зависимости пакета: `php`, `illuminate/support` (для `Stringable`/`Arr` если понадобится). Никакого Eloquent, Laravel framework целиком, доменов.

**НЕ Foundation:** это не контракт расширения, это utility для авторов. Foundation остаётся точкой публичных контрактов (`NodeHandlerInterface` и т.п.).

### Альтернативы (рассмотрены, отвергнуты)

1. **Только DTO без fluent API.** Конструктор с именованными аргументами PHP 8.4 даёт похожий результат, но многословнее: `new NumberField(name: 'seconds', label: '...', default: 60, min: 1)` против `Number::make('seconds')->label('...')->default(60)->min(1)`. Fluent читается лучше при 5+ полях, и Filament-разработчики сразу узнают паттерн. Внутри builder'ы и так хранят readonly DTO — варианты не противоречат.

2. **Кодогенерация из JSON-schema.** Слишком тяжело для текущего масштаба (8–10 типов нод). Окупится только при 30+ типах.

3. **Оставить как есть, ограничиться задачей 03 (reference doc).** Reference doc обязателен в любом случае, но он не решает refactor-safety и автокомплит. Делать оба не дороже, чем один: fluent API ровно ложится на reference как его типизированная проекция.

## Scope задачи

В рамках одной задачи:

1. Дизайн API (один ревью-пасс на сигнатуры до начала кодинга).
2. Реализация Phase 1 vocabulary в `fapost/support`.
3. Юнит-тесты на каждый field-type (build → toArray → expected shape).
4. Golden contract test: для каждого существующего Core handler'а — `Schema::...->toArray() === legacy array`.
5. Миграция Core handler'ов (`Delay`, `Subflow`, `RagQuery`, `EmitEvent`, `End`, `Call` — те, что остаются на generic renderer'е после Storage UI).
6. Обновление `03-schema-reference.md`: каждый field-type документируется и в виде raw shape, и в виде fluent примера.

Что **не входит**:

- Phase 2 vocabulary (`Object`/`KeyValue`/`ObjectArray`/`Validator`) — добавляется отдельной задачей вместе с Phase 2 типов рендерера.
- Изменения в `SchemaConfigRenderer.vue` — wire-формат не меняется.
- Backend валидация config'а ноды по схеме (это отдельная история, ортогональная авторингу).

## Acceptance criteria

- [ ] `fapost/support` содержит namespace `Fapost\Support\Builder\Schema` (или согласованный аналог) с Phase 1 vocabulary.
- [ ] Все Core handler'ы на generic renderer'е используют fluent API.
- [ ] `php artisan test --filter=Schema` — все тесты зелёные, включая golden contract тест.
- [ ] `SchemaConfigRenderer.vue` не изменён.
- [ ] `03-schema-reference.md` показывает оба варианта (raw + fluent) для каждого field-type.
- [ ] PHPDoc на каждом public методе билдеров (CLAUDE.md § Documentation).
- [ ] Pint чистый.

## Риски и mitigations

- **Риск:** vocabulary всё-таки уползёт в Phase 2 и сломает API билдеров.
  **Mitigation:** делать **после** задачи 03 (reference). Если Phase 2 ещё в полёте — отложить эту задачу, не наоборот.

- **Риск:** Solutions/Plugins начнут зависеть от внутренних классов билдеров и сломаются при апгрейде.
  **Mitigation:** только `Schema`, `Section`, конкретные `*Field` классы и `Validator` — публичные. Внутренний DTO-слой помечен `@internal`. Семвер пакета `fapost/support`.

- **Риск:** двойной maintenance (старый массив + fluent) во время миграции.
  **Mitigation:** Golden contract test делает миграцию механической; план — мигрировать все Core handler'ы в одной PR'ке, не оставлять смесь надолго.

## Триггер для старта

Запуск задачи имеет смысл когда выполнено **оба**:

1. Задача 03 (Schema reference) закрыта — vocabulary зафиксирован.
2. Появился первый Solution/Plugin handler в реальной разработке (т.е. внешний потребитель API, не только Core) — это превращает «приятную эргономику» в «обязательную инвестицию в DX расширений».

До этого момента задача остаётся в backlog'е builder-renderer'а.

---

## Связано с

- [[03-schema-reference]] — schema reference
- [[05-vendor-glob]] — vendor glob
- [[06-frontend-extension-boundary]] — ADR
