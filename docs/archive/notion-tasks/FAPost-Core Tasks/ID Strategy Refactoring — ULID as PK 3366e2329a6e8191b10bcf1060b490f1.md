# ID Strategy Refactoring — ULID as PK

> Архив Notion. Актуальная документация: [[03-id-strategy-ulid]]


Depends on: 06b
Domain: Assistant
Phase: 1 — Core Platform
Sprint: 3
Status: Готово
Task №: 6.4

## Цель

Привести все существующие миграции и модели в соответствие с ADR-03: `id` — ULID в PostgreSQL `uuid` колонке, `external_id uuid NULL` — отдельная колонка для будущих внешних интеграций.

> Референс: **ADR-03 — ID Strategy: ULID as PK, UUID for external integrations**
> 

---

## Принципы

- Миграции не меняются — проект ещё не в проде, поэтому переписываем миграции напрямую
- Migration Isolation Contract соблюдается: внутри `up()`/`down()` нет tenant-aware логики
- `tenant_id` в landlord-схеме — не трогаем (зона SaaS-оболочки)

---

## Шаг 1 — Создать Shared Trait

```php
// app/Domains/Shared/Concerns/HasUlidPrimaryKey.php

namespace App\Domains\Shared\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

trait HasUlidPrimaryKey
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return strtolower((string) Str::ulid()->toRfc4122());
    }

    public function uniqueIds(): array
    {
        return ['id'];
    }
}
```

---

## Шаг 2 — Рефакторинг миграций

Проверить все файлы миграций (`database/migrations/tenant/` и `landlord/`) на предмет наличия/отсутствия:

**Требует замены:**

```php
// Было
$table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
// Стало — default убираем, Laravel сам генерирует ULID
$table->uuid('id')->primary();
```

**FK колонки — проверить что используется `foreignUuid()`:**

```php
// Правильно — создаёт индекс автоматически
$table->foreignUuid('assistant_id')->constrained()->cascadeOnDelete();
```

**Таблицы для проверки:**

- `assistants`
- `channels`
- `users`
- `roles`, `permissions`, `user_roles`
- `user_assistants`
- `contacts`, `contact_groups`, `contact_group_members`

Все последующие миграции (flow, messages и далее) уже пишутся по новому стандарту — рефакторинг не нужен.

---

## Шаг 3 — Рефакторинг моделей

Добавить `HasUlidPrimaryKey` во все модели:

```php
// app/Domains/Assistant/Models/Assistant.php
use App\Domains\Shared\Concerns\HasUlidPrimaryKey;

class Assistant extends Model
{
    use HasUlidPrimaryKey;
    // ...
}
```

**Модели для обновления:**

- `Assistant`, `Channel`
- `User`
- `Role`, `Permission`
- `Contact`, `ContactGroup`

Убрать `$incrementing = false` и `protected $keyType = 'string'` если они были прописаны явно — `HasUlids` уже выставляет эти значения.

---

## Шаг 4 — phpat-правило

Добавить правило в архитектурные тесты:

```php
// tests/Architecture/IdStrategyTest.php

Arch::rule('All tenant models must use HasUlidPrimaryKey')
    ->classes()
    ->that()->resideInANamespace('App\Domains\*\Models')
    ->should()->useTrait(HasUlidPrimaryKey::class);
```

---

## Что НЕ делать

- Не менять `tenant_id` в landlord-схеме (`tenants` таблица) — зона SaaS-оболочки
- Не трогать `webhook_public_hash` — он остаётся random string
- Не добавлять `external_id` всем — только по мере реальной необходимости
- Не менять синтаксис `foreignUuid()` в миграциях — он остается

---

## Ожидаемый вывод

```
PHPUnit

IdStrategyTest
  ✓ All tenant models use HasUlidPrimaryKey

ArchitectureTest (phpat)
  ✓ Models in Domains\* use HasUlidPrimaryKey trait

AssistantServiceTest
  ✓ test_create_assistant_has_ulid_format_id

ChannelServiceTest
  ✓ test_create_channel_has_ulid_format_id
```