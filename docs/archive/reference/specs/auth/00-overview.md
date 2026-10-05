# Auth — план миграции на Policy + расширение permissions

Цель: привести всю авторизацию в Filament к единому стилю (Policy + granular Permission), расширить набор permissions так, чтобы через UI можно было собирать осмысленные кастомные роли (раньше `manage_flow` давал «всё про flow», что блокировало роль вроде «только публиковать»).

## Текущее состояние (для контекста реализации)

- **Permission enum** (`app/Domains/Staff/Enums/Permission.php`) — 12 coarse cases, сгруппированы через `group()`. Single source of truth для seed/policy/UI.
- **RoleEnum** (`app/Domains/Staff/Enums/RoleEnum.php`) — 3 системные роли (Admin/ContentManager/Analyst). Каждая → массив Permission cases в `permissions()`.
- **RoleSeeder** (`database/seeders/RoleSeeder.php`) — идёмпотентно: создаёт permissions из enum, синхронизирует системные роли с их permission sets, выполняется при provision tenant'а через `AclBootstrapService`.
- **5 Policy классов** уже есть: Assistant, Channel, Role, User, MediaFolder, MediaFile.
- **11 inline auth checks** в Filament — смесь `Auth::user()->isAdmin()`, `->can(Permission::X->value)`, `Gate::authorize(...)`.

## Карта задач

| # | Задача | Зависит от | Дни |
|---|--------|------------|-----|
| 01 | Расширение Permission enum + RoleEnum updates + i18n | — | 1 |
| 02 | Policy классы для непокрытых ресурсов | 01 | 1–1.5 |
| 03 | Миграция inline проверок на единый Policy/Gate стиль | 02 | 0.5 |
| 04 | Filament Roles UI — descriptions, grouping, tooltips | 01 | 0.5 |

Итого ~3-3.5 дня focused.

## Что НЕ входит

- `audit_log` (отдельный модуль — таблица + сервис + viewer). Может идти следом, но не блокирует Policy unification.
- Per-assistant scoped permissions (`manage_flow` для assistant=X, отдельно от assistant=Y). Это значимое усложнение модели; сейчас permission либо есть глобально, либо нет. Откладывается до multi-assistant prod scenario.
- Multi-tenant boundary hardening через явные tenant_id checks в policies — сейчас schema isolation per tenant + `TenantContext` достаточно.

## Безопасность миграции

- **Backward compat permissions:** старые case'ы `Permission::ManageFlow`, `ManageAssistants`, `ManageSettings` НЕ удаляются. Они остаются в enum, чтобы существующие проверки не сломались. Новые гранулярные case'ы добавляются параллельно. Старые помечаются `@deprecated` PHPDoc — но runtime валидация на них не срабатывает.

- **Re-seed системных ролей:** после расширения enum при следующем запуске seed (или явно через `php artisan ops:tenants-migrate`+seed) системные роли получат новые permissions. Кастомные роли (если есть) остаются с тем permission set который был задан UI — администратор мигрирует вручную.

- **Идёмпотентность** обеспечивается тем что RoleSeeder use updateOrCreate + syncPermissions.

## Зависимости / ссылки

- `app/Domains/Staff/Enums/Permission.php` — основной файл для Фазы 1
- `app/Domains/Staff/Enums/RoleEnum.php` — обновление в Фазе 1
- `database/seeders/RoleSeeder.php` — без изменений (читает enum dynamically)
- `lang/{en,ru,uk}/staff.php` — добавление labels/descriptions
- `app/Domains/{Flow,Assistant,Tenancy,Media}/Policies/*` — Фаза 2
- `app/Filament/**` — Фаза 3 (replace inline) + Фаза 4 (Roles form)

---

## Связано с

- [[01-phase-permissions-expansion]] — фаза 1
- [[02-phase-policy-classes]] — фаза 2
- [[03-phase-migrate-inline-checks]] — фаза 3
- [[04-phase-roles-ui]] — фаза 4
- [[auth-request]] — auth_request нода
- [[03-staff-users]] — Staff пользователи
