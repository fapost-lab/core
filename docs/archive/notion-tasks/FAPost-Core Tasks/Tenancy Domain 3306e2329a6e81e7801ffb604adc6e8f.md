# Tenancy Domain

> Архив Notion. Актуальная документация: [[01-overview-layers]]


Depends on: 01
Domain: Tenancy
Phase: 0 — Фундамент
Sprint: 1
Status: Готово
Task №: 2

## Состав

- `TenantInterface` / `TenantContext` / `TenantRepository` / `TenantDatabaseManager` — контракты
- `Tenant` модель
- `TenantStatus` enum
- `TenantSettings`
- Миграция `landlord.tenants`
- `TenantContext` unit tests

## Ключевые решения

- `TenantContext` — стековая изоляция с `restore()` / `LogicException` на пустом стеке
- `runForTenant()` с гарантией `finally`
- `switchTo()` как глобальная мутация — **запрещён**, только стековая изоляция