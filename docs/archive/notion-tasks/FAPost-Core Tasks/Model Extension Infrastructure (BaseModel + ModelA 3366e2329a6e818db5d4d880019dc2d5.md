# Model Extension Infrastructure (BaseModel + ModelAttributeRegistry)

> Архив Notion. Актуальная документация: [[01-overview-layers]]


Depends on: 01
Domain: Tenancy
Phase: 0 — Фундамент
Sprint: 1
Status: Готово
Task №: 4.1

## Цель

Создать инфраструктуру расширения моделей для Solutions, Features и Plugins — без изменения кода Core-моделей.

## Контекст

Solutions (внешние composer-пакеты) должны добавлять computed attributes и relations к Core-моделям (например, `Contact`) в своих ServiceProvider, не трогая Core. Нужен единый механизм регистрации и резолвинга.

## Решения

- **Relations** — через встроенный `Model::resolveRelationUsing()` (Laravel). Изобретать не нужно. Relations остаются в моделях — Eloquent `with()`/`whereHas()` требует метод на классе; registry для relations невозможен без потери Eloquent magic.
- **Computed attributes** — через `ModelAttributeRegistry` (singleton в контейнере) + перехват `__get` в `BaseModel` (trait `HasComputedAttributes`).
- **Method delegation** — через trait `InteractWithUtilities`: `__call` делегирует неизвестные методы в utility class (implements `ModelUtilityInterface`). Opt-in через `$utilitiesClass` на модели.
- **Custom builder** — через trait `InteractWithBuilder`: `newEloquentBuilder` подставляет кастомный Builder. Opt-in через `$customBuilder` на модели.
- **Сериализация** — через `attributesToArray()` для атрибутов с флагом `append: true`.

## Что НЕ делать

- НЕ переопределять `getAttribute()` — вызывается слишком часто, ломает Eloquent internals
- НЕ использовать статический singleton (`static $instance`) — не совместим с тестами
- НЕ смешивать registry для relations и attributes в один класс
- НЕ выносить relations в registry — теряется вся Eloquent machinery (`with()`, `whereHas()`, `withCount()`)

## Какие модели наследуют BaseModel

Все модели могут безопасно наследовать `BaseModel` — все три трейта инертны без объявления соответствующего свойства. `ModelAttributeRegistry` — singleton, overhead минимален (один lookup по ключу в массиве).

Опциональные свойства для активации расширений:

- `$utilitiesClass = MyUtility::class` — активирует `InteractWithUtilities`
- `$customBuilder = MyBuilder::class` — активирует `InteractWithBuilder`
- Регистрация в `ModelAttributeRegistry` — активирует `HasComputedAttributes`

Пример регистрации в Solution ServiceProvider:

```php
// Computed attribute
app(ModelAttributeRegistry::class)->register(
    Contact::class,
    'department_name',
    fn(Contact $c) => $c->employee?->department,
);

// Relation (встроенный Laravel механизм)
Contact::resolveRelationUsing('employee', fn(Contact $c) => $c->hasOne(Employee::class, 'contact_id'));
```

## Файловая структура

```jsx
app/
  Domains/
    Shared/
      Models/
        BaseModel.php                         # abstract, use HasComputedAttributes + InteractWithUtilities + InteractWithBuilder
      Concerns/
        HasComputedAttributes.php             # __get + attributesToArray через ModelAttributeRegistry
        InteractWithUtilities.php             # __call → $utilitiesClass (implements ModelUtilityInterface)
        InteractWithBuilder.php               # newEloquentBuilder → $customBuilder
      Contracts/
        ModelUtilityInterface.php             # маркер-контракт для utility классов
      Infrastructure/
        ModelAttributeRegistry.php            # singleton, register/has/resolve/serializable
  Providers/
    AppServiceProvider.php                    # $this->app->singleton(ModelAttributeRegistry::class)
```

**Важно:** `app()` в трейтах — допустимое исключение для Eloquent-слоя (Eloquent обходит constructor DI). Изолирован в трейтах с явным комментарием.

## Контракт ModelAttributeRegistry

```php
public function register(
    string $modelClass,
    string $name,
    Closure $resolver,
    bool $append = false,  // true = попадает в toArray()/toJson()
): void

public function has(string $modelClass, string $name): bool
public function resolve(string $modelClass, string $name, Model $model): mixed
public function serializable(string $modelClass): array  // только append=true
```

**Защита от конфликтов**: повторная регистрация одного имени на одном классе — `LogicException`.

## Регистрация в Solution ServiceProvider

```php
// Relations — встроенный Laravel механизм
Contact::resolveRelationUsing('employee', fn(Contact $c) => $c->hasOne(Employee::class, 'contact_id'));

// Computed attributes
app(ModelAttributeRegistry::class)->register(
    Contact::class,
    'department_name',
    fn(Contact $c) => $c->employee?->department,
    append: false,  // не нужен в toArray по умолчанию
);
```

## Предупреждения

- **N+1**: резолвер с обращением к relation — ответственность вызывающего (eager load через `with()`)
- **Наследование**: `has()` ищет только по `static::class`, не по родительским классам. Если понадобится `TenantContact extends Contact` — добавить `class_parents()` lookup
- **Тесты**: `app()->forgetInstance(ModelAttributeRegistry::class)` для сброса между тест-кейсами

## Ожидаемый результат

- `BaseModel` с корректными хуками (`__get`, `attributesToArray`)
- `ModelAttributeRegistry` как контейнерный singleton
- Unit-тесты: регистрация, резолв, конфликт (LogicException), serializable фильтрация
- Пример регистрации в `HrSolutionServiceProvider` (заглушка, реальная реализация в задаче 23)