# Tenant infrastructure

> Архив Notion. Актуальная документация: [[01-overview-layers]]


Depends on: 02
Domain: Tenancy
Phase: 0 — Фундамент
Sprint: 1
Status: Готово
Task №: 3

## Состав

- `TenantRepository` (Eloquent)
- `TenantDatabaseManager`: schema create / switch / migrate / exists
- Redis webhook registry write при создании бота
- **Migration Isolation Contract** зафиксирован как phpat-правило

## Migration Isolation Contract

Миграция — чистая DDL-операция. Запрещено внутри `up()` / `down()`:

- `app()`, `config()`, `env()` (кроме connection name как константы)
- `TenantContext::get()` или любой tenant-aware сервис
- Условные ветки на основе module activation или feature flags
- Seed-данные зависящие от runtime состояния
- Из миграции модуля: `DB::table()` поверх таблиц платформы или другого модуля

> ⚠ Enforcement: phpat-правило в CI, начиная с этой задачи
>