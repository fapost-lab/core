# Tenancy middleware & boot

> Архив Notion. Актуальная документация: [[01-overview-layers]]


Depends on: 03
Domain: Tenancy
Phase: 0 — Фундамент
Sprint: 2
Status: Готово
Task №: 4

## Состав

- `TenancyMiddleware`
- `CoreBootstrap`
- `DomainServiceProvider` chain
- `platform:install` artisan command
- `ModuleRegistrarInterface` — заглушка (контракт без реализации)

## Примечания

> ⚠ `ModuleRegistrarInterface` — только заглушка. Полная реализация в Фазе 4 (задача 21).
> 

> ⚠ Спринт 2 начинается только после того как задачи 01–03 покрыты тестами и provisioning контракт не меняется.
>