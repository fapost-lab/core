# 05 · Backend variable contract + резолвер

**Зависит от:** —
**Блокирует:** —
**Слой:** backend (PHP)

## Цель

Единый сервис, который преобразует UI-shape `{ name, storage, group }` в физический path в state JSON / contact attributes. Резолвер используют все handlers, у которых есть «save user data» концепт (Input, SendMessage button save_to, Assign, в будущем — модули).

Резолвер должен быть симметричен для записи и чтения: один и тот же UI-Variable превращается в один и тот же путь и в `flow_sessions.state`, и в `contact.attributes`.

## Контракт

```php
namespace App\Domains\Flow\State\Variables;

/**
 * Описание пользовательской переменной как видит её author flow в UI.
 * Compile-time представление; в runtime engine разворачивается в путь
 * через {@see VariableResolver}.
 */
final readonly class Variable
{
    public function __construct(
        public string $name,                 // alphanumeric + underscore
        public string $type,                 // 'text'|'number'|'phone'|...
        public VariableStorage $storage,     // enum: Contact | Session
        public ?string $group = null,        // ровно один уровень или null
    ) {}

    /**
     * Создать из массива конфигурации ноды; null если структура неполная.
     * @param array<string, mixed> $raw
     */
    public static function tryFromArray(array $raw): ?self { ... }
}

enum VariableStorage: string
{
    case Contact = 'contact';
    case Session = 'session';
}

interface VariableResolverInterface
{
    /**
     * Возвращает целевой path для записи через ScopedStateWriter.
     *  - Contact + name + null group → `contact.attributes.{name}`
     *  - Contact + name + group      → `contact.attributes.{group}.{name}`
     *  - Session + name              → `flow.{name}`
     *
     * Group depth >1 приводит к InvalidArgumentException.
     */
    public function resolveTargetPath(Variable $variable): string;

    /**
     * Чтение того же значения через resolver chain (ScopedStateReader).
     */
    public function read(Variable $variable, FlowContext $context): mixed;

    /**
     * Backward-compat: разворачивает legacy `save_to` строку в Variable.
     * Поддерживает форматы:
     *  - "flow.foo"           → Session, name=foo, group=null
     *  - "contact.foo"        → Contact, name=foo, group=null
     *  - "contact.bar.foo"    → Contact, name=foo, group=bar
     *  - "foo" (без префикса) → Session, name=foo, group=null   (исторический дефолт)
     *  - "contact.a.b.c"      → throws (depth >1)
     */
    public function fromLegacyPath(string $path): Variable;
}
```

## Реализация

`VariableResolver` — singleton stateless. Не зависит от tenant context: все операции — чистые трансформации path-строк. Контекст для read() приходит через `FlowContext` (есть session, contact, и т.д.).

Запись — всегда через `ScopedStateWriter`. Резолвер не пишет сам — только возвращает path.

## Reserved keys

`Variable::tryFromArray` отвергает следующие имена переменных (соотв. п.4.6 спеки и существующим reserved keys в ContactWriter):
- `id`, `channel_id`, `tenant_id`, `external_id`, `meta`, `language`, `is_blocked`, `created_at`, `updated_at`

Группа `meta` отвергается (зарезервирована для платформенных данных).

## Schema validation

`FlowDefinitionValidator` дополнительно проверяет в config нод Input/SendMessage/Assign:
- Если есть и `variable` (новый формат), и `save_to` (legacy) одновременно → ошибка валидации (двойной контракт).
- `Variable` структура валидна (имя, group depth ≤ 1, reserved keys).
- Внутри одной Assign ноды — `operations[*].variable` пары `(storage, group, name)` уникальны.

## Handlers — изменения

Каждый handler, использующий variable, получает `VariableResolverInterface` через DI и вызывает `resolveTargetPath()` вместо прямой конкатенации:

| Handler | Поле в config | Что меняется |
|---------|---------------|--------------|
| InputNodeHandler | `variable` (новый) / `save_to` (legacy) | путь резолвится через resolver |
| SendMessageNodeHandler | `save_to_variable` (новый) / `save_to` + `save_to_type` (legacy) | то же |
| AssignNodeHandler | `operations[*].variable` (новый) / `target+key` (legacy) | то же, в цикле |

В каждом handler — один `if (variable)` бранч → resolver, иначе legacy fallback. Когда все ноды мигрированы и legacy snapshots в БД отсутствуют — бранч можно убрать.

## Миграция данных

**Не нужна.** Legacy snapshots остаются как есть; на чтении handler детектирует формат и работает с обоими. При первом редактировании flow в builder'е (после задач 02–04) snapshot перезапишется в новый формат через autosave.

## Файлы

- `app/Domains/Flow/State/Variables/Variable.php` — value object
- `app/Domains/Flow/State/Variables/VariableStorage.php` — enum
- `app/Domains/Flow/Contracts/VariableResolverInterface.php` — contract
- `app/Domains/Flow/State/Variables/VariableResolver.php` — реализация
- `app/Domains/Flow/Providers/FlowServiceProvider.php` — bind contract → impl
- `app/Domains/Flow/Validation/FlowDefinitionValidator.php` — добавить проверки
- `app/Domains/Flow/Handlers/{Input,SendMessage,Assign}NodeHandler.php` — wire resolver

## Тестирование

Unit тесты `VariableResolverTest`:
- Resolve target path для всех комбинаций storage × group.
- `fromLegacyPath()` парсит все форматы; depth >1 → throws.
- Reserved keys отвергаются.
- `Variable::tryFromArray` корректно валидирует.

Feature тест: existing flow с legacy `save_to: "flow.foo"` в SendMessage → button click → state.flow.foo записан.

## Acceptance

- Resolver покрыт unit'ами для всех бранчей.
- Все три handler'а имеют integration тест с обеими формами snapshot.
- FlowDefinitionValidator отвергает невалидные variable shape'ы с понятным сообщением.

---

## Связано с

- [[00-overview]] — overview storage
- [[10-state-writer-semantics]] — ADR state writer
- [[01-state-model]] — state model
- [[10-variable-type-coercion]] — type coercion
- Запуск `php artisan ops:tenants-migrate` на пустую тенант-схему проходит без ошибок (контракт без миграции данных).
