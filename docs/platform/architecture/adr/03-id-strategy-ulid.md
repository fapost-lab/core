# ADR-03 — ID Strategy: ULID as PK, UUID for external integrations

> **Superseded in part (2026-10).** The trait is `Fapost\Support\Concerns\HasUlidPrimaryKey`, shipped by the
> `fapost-support` package; the `app/Domains/Shared/Concerns/` location named below no longer exists.

**Статус:** Принято · Апрель 2026

**Контекст**

UUID v4 случаен — каждая вставка попадает в произвольное место B-tree индекса, что вызывает page splits и фрагментацию. На горячих таблицах (`flow_sessions`, `flow_logs`, `contacts`) это создаёт измеримую деградацию производительности при росте данных. ULID монотонен по времени — новые записи всегда вставляются в конец индекса, что устраняет page splits и улучшает cache locality.

---

## Решение

### Primary Key — ULID в UUID-колонке

- Все таблицы платформы и модулей используют **ULID** в качестве первичного ключа
- Хранится в нативном PostgreSQL `uuid` типе (ULID — 128 бит, совместим с UUID бинарно)
- Laravel трейт `HasUlids` с переопределённым `newUniqueId()`
- FK колонки: `foreignUuid('..._id')->constrained()->cascadeOnDelete()` — без изменений

```php
// packages/fapost-support: Fapost\Support\Concerns\HasUlidPrimaryKey
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

```php
// Миграция — без изменений синтаксиса
$table->uuid('id')->primary();
$table->foreignUuid('assistant_id')->constrained()->cascadeOnDelete();
```

### External ID — UUID v4, отдельная колонка

- `external_id uuid NULL` — опциональная колонка на сущностях где планируется внешняя интеграция
- Генерируется явно при необходимости, не автоматически
- Не является PK, не участвует в FK-связях внутри платформы
- Конкретный список таблиц определяется по мере необходимости — контракт фиксируется сейчас, применяется точечно

```sql
external_id UUID NULL
```

### Что остаётся без изменений

- `webhook_public_hash` на `channels` — случайная строка, opaque токен, не ULID/UUID
- Redis ключи — строки по существующим контрактам
- `tenant_id` в landlord схеме — остаётся UUID (SaaS-оболочка вне этого ADR)

---

## Последствия

**Плюсы:**

- Монотонная вставка — нет page splits на горячих таблицах
- Лексикографическая сортировка по `id` = сортировка по времени создания
- Нативный PostgreSQL `uuid` тип — индексы, FK, операторы без изменений
- Совместимость с `foreignUuid()` — миграции не усложняются

**Компромиссы:**

- ULID в RFC4122 формате визуально выглядит как UUID — это намеренно
- При отладке нужно помнить что `id` — ULID, не UUID v4
- Primary IDs are generated exclusively at application layer through `HasUlidPrimaryKey`. Raw inserts must explicitly provide `id`.

---

## Enforcement

- Все новые миграции: `$table->uuid('id')->primary()` + трейт `HasUlidPrimaryKey` в модели
- Рефакторинг существующих миграций: задача **06d — ID Strategy Refactoring**
- phpat-правило (добавить в задаче 06d): все модели кроме landlord-схемы обязаны использовать `HasUlidPrimaryKey`

---

## Связано с

- [[01-overview-layers]] — общая архитектура платформы
- [[05-contacts]] — Contact Domain (использует ULID PK)
- [[04-assistant-domain]] — Assistant Domain (использует ULID PK)
- [[diagrams/03-tenant-context]] — tenant context и переключение схемы