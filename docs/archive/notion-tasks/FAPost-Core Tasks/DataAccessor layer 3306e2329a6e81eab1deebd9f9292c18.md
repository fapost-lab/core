# DataAccessor layer

> Архив Notion. Актуальная документация: [[05-foundation-contract-package]]


Depends on: 13
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 5
Status: Готово
Task №: 14

## Цель

Обеспечить единый runtime-контракт доступа к данным модулей внутри Flow Engine без прямого чтения модульных таблиц, с обязательной фиксацией фактически использованных значений в `flow_logs` при каждом переходе `condition` ноды.

## Контекст

Согласно архитектурному контракту платформы:

- `condition` нода не имеет права читать `module.*` напрямую
- чтение допустимо только через `DataAccessorInterface`
- accessor регистрируется модулем через `ModuleRegistrarInterface`
- `flow_logs` обязаны сохранять snapshot resolved values для аудита принятого решения

## Состав работ

### 1. DataAccessorInterface

Определить контракт:

```php
interface DataAccessorInterface
{
    public function get(string $namespace, string $key, ContactInterface $contact): mixed;
}
```

- `namespace` поддерживает схему `module.{module}.{field}`
- accessor не содержит tenant resolution — работает внутри уже установленного tenant context
- ошибки namespace → controlled exception, не silent fallback

### 2. DataAccessorRegistry

Реализовать registry:

- регистрация accessor по namespace owner (prefix, например `module.hr`)
- in-memory registry, собирается при boot через registrar
- hard fail при duplicate namespace — до начала трафика
- hard fail при отсутствии accessor для `module.*` namespace

Минимальный API:

```php
public function register(string $namespacePrefix, DataAccessorInterface $accessor): void;
public function resolve(string $namespacePrefix): DataAccessorInterface; // по owner-prefix, не по полному пути
```

> ⚠ `resolve()` работает по owner-prefix (`module.hr`), а не по полному ключу (`module.hr.department`). Парсинг пути — ответственность engine, не registry.
> 

### 3. Зарезервированные namespace-префиксы

Namespace-префиксы `flow.*`, `system.*`, `rag.*` **зарезервированы** — определяются статически на уровне engine, не расширяемы через accessor registry.

- Попытка зарегистрировать accessor с зарезервированным prefix → hard fail при boot
- `condition` нода читает `flow.*`, `system.*`, `rag.*` через state resolver; `module.*` — исключительно через registry → accessor
- Split authority между двумя путями резолюции запрещён

### 4. Condition node refactor

Перевести resolution condition operands:

- `flow.*`, `system.*`, `rag.*` → state resolver (без изменений)
- `module.*` → registry → accessor (исключительно)
- прямой доступ к DB из condition handler запрещён; enforcement через phpat-rule
- resolved value вычисляется до сравнения

### 5. flow_logs snapshot

При каждом condition transition писать:

```json
{
  "node_id": "uuid",
  "node_type": "condition",
  "resolved": {
    "module.hr.department": "logistics"
  },
  "expression": {
    "operand": "module.hr.department",
    "operator": "eq",
    "expected": "logistics"
  },
  "transition": "node_x"
}
```

> ⚠ `node_id` — UUID ноды из graph, не тип. `node_type` — отдельное поле. При нескольких `condition` нодах в одном flow аудит должен однозначно идентифицировать каждый переход.
> 

## Acceptance Criteria

- `condition` нода проходит через accessor для любого `module.*`
- registry доступен через container
- accessor подключается модулем через registrar
- попытка зарегистрировать accessor с зарезервированным namespace-prefix (`flow`, `system`, `rag`) → hard fail при boot
- duplicate namespace → hard fail при boot
- отсутствие accessor для `module.*` → deterministic exception (не silent fallback)
- `flow_logs` фиксирует `node_id`, `node_type`, `resolved`, `expression`, `transition`
- phpat-rule запрещает прямой доступ к DB из condition handler

## Риски

- прямое чтение модульных таблиц в старом handler должно быть полностью удалено
- нельзя допустить split authority между state resolver и accessor resolver — строгое разделение по namespace prefix
- snapshot должен фиксировать именно resolved runtime value, а не expression

## Примечания

> ⚠ Контракт уже заложен в `ModuleRegistrarInterface` из задачи 04 — реализация встраивается без нового параллельного registration механизма.
> 

> 
> 

> `resolve()` в registry работает по owner-prefix (`module.hr`), а не по полному пути (`module.hr.department`). Cursor не должен делать `strpos`/`explode` внутри `resolve()` — парсинг пути остаётся на стороне engine.
>