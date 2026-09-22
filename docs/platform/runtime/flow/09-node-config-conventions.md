# 09. Node Config Conventions

Единый стиль для всех новых нод, чтобы:

- config был предсказуемым,
- transition handles были стабильными,
- state keys не превращались в хаос,
- ошибки валидации были одинаковыми по формату.

---

## 1) Базовые правила именования

### `type`

- snake_case, коротко и по смыслу.
- Начинай с глагола/действия, если нода выполняет действие: `send_message`, `set_attribute`, `notify_staff`.
- Для чистой логики допустимы предметные названия: `condition`, `switch`, `delay`.

### поля в `config`

- snake_case.
- имена "что это", не "как используется":
    - `timeout_seconds`, а не `timeout` (если важна единица измерения),
    - `save_to`, а не `var`.
- булевы поля начинай с `is_` / `has_` там, где это улучшает читаемость (`is_required`, `has_fallback`).

### transition handles (`sourceHandle`)

- только стабильные строки, без динамической генерации.
- рекомендованные базовые:
    - `default`
    - `success` / `error`
    - `true` / `false`
    - `invalid`
    - `timeout`
    - `no_response`

---

## 2) Формат `config`: обязательные требования

`config` должен быть:

- сериализуемым в JSON,
- без runtime-объектов/классов,
- backward-compatible в рамках одной версии ноды.

### Нормализация в handler-е

В `execute()` всегда делай:

1. `is_array($nodeConfig['config'] ?? null) ? ... : []`
2. извлечение и строгую проверку типов,
3. дефолты только там, где это действительно безопасно.

### Единицы измерения

Если поле про время/размер, это должно быть явно в имени:

- `delay_seconds`
- `max_length`
- `retry_limit`

---

## 3) `configSchema()` конвенции

`configSchema()` - это контракт между backend и builder. Собирается через fluent Builder API
`Fapost\Support\Builder\Schema` (`Schema`/`Section`/`Fields\*`), а не через сырые массивы;
`Schema::toArray()` строит ровно тот wire-формат, который понимает Vue-рендерер. Пример реального
handler-а - `SetTagNodeHandler` (`app/Domains/Flow/Handlers/SetTagNodeHandler.php`):

```php
return Schema::make()
    ->required(['action'])
    ->section(
        Section::make('action', (string) __('builder.nodes.set_tag.section'))
            ->icon('tag')
            ->fields([
                SelectField::make('action')
                    ->label((string) __('builder.nodes.set_tag.action'))
                    ->required()
                    ->default(TagAction::Add->value)
                    ->options(TagAction::options()),
                ArrayField::make('tags')
                    ->label((string) __('builder.nodes.set_tag.tags'))
                    ->help((string) __('builder.nodes.set_tag.tags_help')),
            ]),
    )
    ->toArray();
```

Для каждого поля указывай минимум:

- имя (`Field::make('name')`),
- `->label(...)`,
- `->required()`, если поле обязательно.

Если уместно, добавляй:

- `->default(...)`
- `->placeholder(...)`
- `->options([...])` (для select-like полей)

### Доступные типы полей (`Fapost\Support\Builder\Schema\Fields`)

`TextField`, `TextareaField`, `NumberField`, `ToggleField`, `SelectField`, `ArrayField`,
`ObjectField`, `ObjectArrayField`, `KeyValueField`, `JsonField`, `DurationField`,
`StatePickerField`, `FlowPickerField`, `EnumCardsField` - специализированный UI (клавиатуры,
мультиязычность и т.п.) всё ещё требует кастомного override-компонента в builder (см.
`06-node-development-guide.md` §13).

---

## 4) State keys conventions

### Общие правила

- Не использовать magic strings для системных ключей.
- Для `system.*` использовать константы (`SystemStateKeys::*`).
- Для `flow.*` ключей использовать предсказуемую структуру.

### Паттерн для `flow.*`

- `flow.<domain>.<field>` когда нода пишет структурированные данные.
- Пример:
    - `flow.user.email`
    - `flow.order.total`

Если нода пишет в путь из `config.save_to`, валидируй, что путь соответствует допустимому namespace.

### Что запрещено

- писать в корень без namespace (`"email" => ...`),
- смешивать разную семантику в одном ключе (`flow.tmp` как "всё подряд"),
- использовать `module.*` как место для записи из Core-нод.

---

## 5) Conventions для contact-мутаций

Для изменений вне session state (contact attributes, canonical language и т.п.) используется
`ContactWriterInterface->write(string $path, mixed $value)`, доступный через
`context->contactWriter`. Легаси-механизм `effects[]`, который движок разбирал централизованно,
удалён - `NodeExecutionResult` такого поля не содержит.

Рекомендации:

- `path` всегда начинается с `contact.` и обязан соответствовать contract writer-а (один уровень
  группировки: `contact.<group>.<field>`; зарезервированные ключи `id`, `tenant_id`, `channel_id`,
  `channel`, `meta.*` кидают `LogicException`).
- каждый write коммитится немедленно в своей транзакции - handler не должен полагаться на
  атомарность вместе с сохранением session state.
- документируй, какие `contact.*` пути пишет нода, в PHPDoc/доках ноды.

---

## 6) Ошибки валидации и исключения

### Принцип

- Ошибки должны быть одинаково читаемыми для дебага и API-ответов.

### Рекомендация для текста ошибок

Формат:

- `<node_type>: <краткая причина>`

Примеры:

- `send_message: missing content_type`
- `condition: unsupported namespace in check 'foo.bar'`
- `my_custom_node: invalid retry_limit`

### Где кидать исключение

- В handler-е при некорректном config.
- Не скрывать критическую конфигурационную ошибку за `Waiting`.

---

## 7) Конвенции versioning для config

### Что считается breaking для config

- обязательное поле стало обязательным без дефолта,
- изменился тип поля (`string` -> `array`),
- изменился смысл существующего поля,
- изменились handles, на которые завязан граф.

В этих случаях делай `version++`.

### Что обычно не breaking

- добавление нового необязательного поля,
- улучшение внутренней нормализации без изменения семантики.

---

## 8) Conventions для handles и edges

### Required transitions

Если нода требует конкретные выходы, зафиксируй их:

- в документации ноды,
- в definition через `required_transitions` (где это используется),
- в тестах validator-а.

### Практика

- не переименовывай handle в той же версии ноды,
- не используй локализованные строки как handle,
- не кодируй business payload в handle (`status_123` и т.п.).

---

## 9) Шаблон документации для каждой новой ноды

Для новой ноды добавляй краткий блок (в PR/доках):

- `type`, `version`
- назначение ноды
- структура `config` (поля + типы + required)
- список возможных `sourceHandle`
- какие `stateChanges` пишет
- какие `contact.*` пути пишет через `ContactWriter` (если пишет)
- retry/idempotency стратегия

Это резко упрощает ревью и поддержку.

---

## 10) Мини-checklist перед merge

- [ ] `config` поля именованы по snake_case и понятны по смыслу.
- [ ] `configSchema()` собран через fluent `Schema`/`Section`/`Fields\*` API, поля помечены `->label()`/`->required()`.
- [ ] `sourceHandle` стабильные и документированы.
- [ ] `system.*` ключи идут через константы.
- [ ] Ошибки валидации в едином формате `<node_type>: <reason>`.
- [ ] Проверено: изменение не ломает старые definition текущей версии.

Если чеклист зелёный, нода обычно хорошо "садится" в существующий flow-runtime.
