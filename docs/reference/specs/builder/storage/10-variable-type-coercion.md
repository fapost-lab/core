# 10 · Variable Type Coercion + Schema Registry

**Зависит от:** 02, 04, 05 (Variable VO, VariableResolver, новый формат node configs)
**Блокирует:** улучшения Filament Contact card (task 08 follow-up), Branch operator picker по типам
**Слой:** backend (PHP) + Filament admin

## Контекст

Сейчас `Variable.type` (text / number / phone / email / confirm / date / contact / file / photo / location / select) — UI metadata, runtime его игнорирует. `AssignNodeHandler` пишет в state результат рендера шаблона как строку, никаких коэрсий нет. Это сбивает с толку: пользователь ставит Type=Number, кладёт `{{contact.name}}` в Value — в state ляжет строка "Иван", не NaN.

Сделать Type осмысленным **на чтении**: storage остаётся JSONB-friendly (всё как строки/нативные типы JSON), но при resolve через `VariableResolver::read()` значение приводится к типу, который декларировала переменная.

## Цели

1. Один контракт coercion по `Variable.type` на стороне resolver.
2. Schema registry: per-tenant `(storage, group, name) → type` — единый источник правды для произвольного чтения.
3. Branch использует coerced value → `confirm == true` сравнивает booleans, не строки.
4. Filament Contact card форматирует значения по их декларированному type.

## Coercion contract

`VariableResolver::read(Variable $variable, NodeExecutionContext $ctx): mixed`:

| Type | Правило |
|------|---------|
| `text`, `phone`, `email`, `select` | as-is string; null если отсутствует |
| `number` | строка → `(int)` если без точки, иначе `(float)`. Пусто/нечисловое → null |
| `confirm` | truthy: `true`,`1`,`yes`,`y`,`on`,`да`; falsy: `false`,`0`,`no`,`n`,`off`,`нет`. Регистронезависимо. Прочее → null |
| `date` | `Carbon::parse()`, ошибка → null |
| `contact`, `file`, `photo`, `location` | as-is (платформенные handler-ы уже пишут структурированные значения) |

**Важно:** coercion применяется только когда `Variable.type` известен. Без registry читатель не знает type — fallback на raw value.

Контракт `VariableCoercerInterface` отделить от `VariableResolverInterface`: одна ответственность — приведение типа.

## Schema Registry

### Таблица `tenant_variable_schema`

Per-tenant, в tenant schema. ULID PK. Колонки:

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid (ULID) | PK |
| `storage` | varchar(16) | `contact` \| `session` |
| `group` | varchar(64) NULL | NULL для root |
| `name` | varchar(64) | имя переменной |
| `type` | varchar(16) | значение из enum `VariableType` |
| `declared_in_flow_id` | uuid | FK → `flow_definitions.id`, cascade null on delete |
| `declared_by_node_id` | uuid | id ноды внутри `nodes` JSON |
| `updated_at` | timestamp | |

UNIQUE (`storage`, `group`, `name`).

`session` тоже регистрируем — даже если переменная живёт в state, тип нужен для Branch coercion.

### Сборка при publish flow

`PublishFlowService::publish()` после копирования `flow_drafts → flow_definitions`:

1. Сканирует `nodes` JSON опубликованного flow.
2. Извлекает все объявления переменных:
   - `input`: `config.variable`
   - `assign`: `config.operations[*].variable`
   - `send_message`: `config.save_to_variable`
3. Для каждой `(storage, group, name)` пары — upsert в `tenant_variable_schema`.

**Конфликт:** если уже есть запись с другим `type` от *другого активного flow* → `FlowDefinitionValidator` бросает `VariableTypeConflictException` ещё на этапе validate, до publish. Сообщение: `Variable "{group}.{name}" declared as "{existing_type}" in flow "{other_flow_name}", cannot redeclare as "{new_type}"`.

**Удаление flow / unpublish:** schema-записи с `declared_in_flow_id = removed` чистятся каскадом. Если запись была единственная — переменная исчезает из registry. Если есть другие flows с тем же declaration — registry не трогается.

### Cache layer

`VariableSchemaRegistryInterface`:

```php
public function get(string $storage, ?string $group, string $name): ?VariableType;
public function getAllForTenant(): array; // [(storage, group, name) => VariableType]
public function invalidate(): void;
```

Implementation:
- Read-through Redis cache: `tenant:{tenant_id}:variable_schema` (Hash или JSON blob).
- TTL не ставим (write-through на publish).
- `PublishFlowService::publish()` после schema rebuild → `$registry->invalidate()`.
- Загружается лениво через `scoped` binding (per-request).

## Branch handler — wire coercion

В `BranchNodeHandler::evaluate()` для `left.ref === 'user_variable'`:

```php
$variable = Variable::tryFromArray($left['variable']);
$rawValue = $resolver->read($variable, $ctx);
$leftValue = $coercer->coerce($rawValue, $variable->type);
```

Right-side значение (`right`) тоже коэрсируется в тот же type перед сравнением (если `right` — литерал, то по type Variable; если template — рендерим затем coerce).

`SwitchNodeHandler` — аналогично, если опирается на тот же left-механизм.

## Filament Contact card (follow-up к task 08)

В `ContactInfolistSchema::stringify()` — взять `VariableSchemaRegistry::get('contact', $group, $name)` для каждого поля:
- `number` → `number_format()` с разделителями локали.
- `date` → `Carbon::parse()->translatedFormat()`.
- `confirm` → `'Yes'` / `'No'` (через trans).
- `phone` → форматирование по libphonenumber (если доступен) или as-is.
- Прочее → as-is с fallback на `gettype`.

Если registry не знает field (legacy поле вне любого flow) — current fallback на `gettype()`.

## Файлы

### Новые
- `app/Domains/Flow/State/Variables/VariableType.php` — enum (если ещё нет; сейчас type — string в VO)
- `app/Domains/Flow/Contracts/VariableCoercerInterface.php`
- `app/Domains/Flow/State/Variables/VariableCoercer.php`
- `app/Domains/Flow/Contracts/VariableSchemaRegistryInterface.php`
- `app/Domains/Flow/State/Variables/EloquentVariableSchemaRegistry.php` (или CacheBackedVariableSchemaRegistry)
- `app/Domains/Flow/Models/VariableSchemaEntry.php`
- `app/Domains/Flow/Services/VariableSchemaCollector.php` — сканирует flow nodes, возвращает `array<Variable>` declarations
- `app/Domains/Flow/Exceptions/VariableTypeConflictException.php`
- `database/migrations/tenant/{date}_create_tenant_variable_schema_table.php`

### Изменения
- `app/Domains/Flow/State/Variables/Variable.php` — `type` → `VariableType` enum
- `app/Domains/Flow/Contracts/VariableResolverInterface.php` — `read()` возвращает coerced value через `VariableCoercer`
- `app/Domains/Flow/State/Variables/VariableResolver.php` — wire coercer
- `app/Domains/Flow/Handlers/BranchNodeHandler.php` — read через resolver+coercer
- `app/Domains/Flow/Services/PublishFlowService.php` — после publish: collect schema, upsert, invalidate cache
- `app/Domains/Flow/Validation/FlowDefinitionValidator.php` — conflict detection при validate (cross-flow type collisions)
- `app/Domains/Flow/Providers/FlowServiceProvider.php` — bind contracts
- `app/Filament/Assistant/Resources/Contacts/Schemas/ContactInfolistSchema.php` — type-aware formatting через registry

## Тесты

### Unit
- `VariableCoercerTest` — все правила coercion для каждого type, edge cases (пустая строка, null, неконвертируемое).
- `VariableSchemaCollectorTest` — корректный extract из всех типов нод; повторяющиеся декларации; legacy форматы игнорируются.

### Feature
- `PublishFlowServiceTest` — после publish schema-таблица заполнена; cache invalidate вызван.
- `FlowDefinitionValidatorTest` — conflict между двумя active flows блокирует publish.
- `BranchNodeHandlerTest` — `confirm` сравнение со строкой "yes" → true; `number` сравнение `<` со строковым числом — корректно; `date` сравнение по Carbon.
- `VariableSchemaRegistryTest` — read-through cache, write на publish, invalidate.

### Integration
- E2E сценарий: Input(`expected_type=confirm`, `variable.type=confirm`) → Branch(left=user_variable confirm, op=eq, right=`true`) → пользователь отвечает «yes» → ветка `true` срабатывает.

## Backward compatibility

- Существующие flows без `Variable.type` — fallback на `text` (uncoerced as-is). Это сохраняет текущее поведение.
- Existing JSONB значения — не меняются. Coercion только на чтении.
- Legacy `save_to: "flow.foo"` без типа — Schema collector регистрирует с `text` дефолтом.
- Никакой data migration не нужно.

## Acceptance

- `VariableCoercer` покрыт unit-тестами для всех типов и edge cases.
- `tenant_variable_schema` таблица создаётся при `ops:tenants-migrate`.
- Publish flow с input/assign нодами заполняет schema.
- Cross-flow type conflict ловится валидатором с понятным сообщением.
- Branch с `left.user_variable` корректно коэрсирует значение по типу из registry.
- Filament Contact card форматирует number/date/confirm по типу.
- Старые flows без type-декларации работают как раньше (через text fallback).

## Out of scope

- Migration UI / hint когда type конфликтует — пока validation error в API, без специального UI.
- Coercion для `right` value в Branch когда `right` — template (отдельный pass рендера).
- Type inference из значения (heuristic) — мы не угадываем, опираемся только на явную декларацию.
- Per-tenant локализация yes/no словарей (en/ru/uk покрываются жёстко в coercer; больше — отдельная задача).

## Связанные документы

- `flow-constructor-ui-data-storage.md` — UX-спека (источник)
- `00-overview.md` — карта задач
- `08-contact-card-groups.md` — потребитель type-aware formatting

---

## Связано с

- [[05-backend-contract]] — backend контракт
- [[02-input]] — input нода с typed variables
- [[03-branch]] — branch с coerced values
- `07-branch-source-picker.md` — потребитель coercion на чтении
