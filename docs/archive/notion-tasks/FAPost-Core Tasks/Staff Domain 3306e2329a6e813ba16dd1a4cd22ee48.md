# Staff Domain

> Архив Notion. Актуальная документация: [[03-staff-users]]


Depends on: 04
Domain: Staff
Phase: 1 — Core Platform
Sprint: 3
Status: Готово
Task №: 5

## Состав

- `users`, `roles`, `permissions` миграции
- Базовый Filament panel

## Что решено в рамках этой задачи

См. отдельную задачу: **Роли, права, иерархия и деактивация пользователей** (задача 05-roles)

### Системные роли

| Роль | Priority | Scope |
| --- | --- | --- |
| `admin` | 100 | Полный доступ: боты, flow, рассылки, RAG, пользователи, настройки |
| `content_manager` | 50 | Flow, broadcast, RAG, контакты (список + теги/сегменты, без мета) |
| `analyst` | 30 | Аналитика и дашборды — read-only |

### RoleEnum

`RoleEnum` — единственный источник истины. `RoleSeeder` читает через `CoreRegistrar::getRoles()`. В Фазе 1 — `RoleEnum::cases()`, в Фазе 4 — + роли модулей.

### Деактивация (`is_active`)

- `is_active = false` → немедленная инвалидация сессии
- `EnsureUserIsActive` middleware на каждый запрос
- Нельзя деактивировать единственного admin и себя

### Архитектурная заметка: роли модулей

Каждый модуль регистрирует свои роли через `CoreRegistrar`:

```php
CoreRegistrar::roles(HrRoleEnum::cases());
CoreRegistrar::permissions(HrPermission::cases());
```

`RegisterableRoleInterface` — контракт фиксируется сейчас, реализация модульной части — задача 21.

> ⚠ Открытый вопрос (задача 21): namespace изоляция ролей между модулями — коллизии при одинаковом value. Варианты: префикс модуля (`hr.manager`), реестр с проверкой уникальности при boot.
>