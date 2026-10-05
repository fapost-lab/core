# Фаза 2 · Policy классы для непокрытых ресурсов

**Длительность:** ~1–1.5 дня
**Зависит от:** Фаза 1 (новые гранулярные permissions)
**Блокирует:** Фаза 3

## Цель

Все ресурсы и pages в Filament должны иметь Policy. Сейчас часть защищена только через `getEloquentQuery` фильтрацию, что неустойчиво к URL-tampering и несогласованно.

## Список ресурсов и нужных Policy

### Полноценные Policy классы (Resource-level)

| Resource | Policy | Permission | Notes |
|----------|--------|------------|-------|
| FlowDraft / FlowDefinition | `FlowDraftPolicy` | view→`ViewFlowSessions`/`ManageFlowDefinitions`, publish→`PublishFlow`, edit→`ManageFlowDefinitions` | Один Policy на оба, FlowDefinition обычно используется только для view (immutable snapshots) |
| FlowGroup | `FlowGroupPolicy` | `ManageFlowGroups` | Простой |
| FlowSession | `FlowSessionPolicy` | `ViewFlowSessions` | Read-only resource, только viewAny/view |
| FlowLog | `FlowLogPolicy` | `ViewFlowSessions` | то же |
| TenantTranslation | `TenantTranslationPolicy` | `ManageTranslations` | admin panel |
| AssistantTranslation | `AssistantTranslationPolicy` | `ManageTranslations` (+ assistant assignment check) | assistant panel |

### Page-level access (статический `canAccess`)

Filament Pages не используют Policy в традиционном смысле — у них есть метод `static::canAccess(): bool`. Реализуем там через Gate facade:

| Page | Permission |
|------|-----------|
| `TenantSettingsPage` (admin) | `ManageSettings` |
| `TranslationsPage` (admin) | `ManageTranslations` |
| `TranslationsPage` (assistant) | `ManageTranslations` (+ assistant assigned) |
| `AssistantSettings` (assistant) | `ManageAssistantSettings` |
| `AssistantDashboard` (assistant) | базовая видимость assistant'а (view through AssistantPolicy) |

## Структура Policy класса

Унифицированный шаблон:

```php
<?php

declare(strict_types=1);

namespace App\Domains\Flow\Policies;

use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

/**
 * Authorization policy for {@see FlowDraft}.
 * - viewAny / view / create / update — required ManageFlowDefinitions + assistant assigned
 * - delete                            — required ManageFlowDefinitions + assistant assigned
 * - publish                           — required PublishFlow + assistant assigned
 */
final class FlowDraftPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $user): bool
    {
        return $user instanceof User
            && ($user->can(Permission::ManageFlowDefinitions->value)
                || $user->can(Permission::ViewFlowSessions->value));
    }

    public function view(AuthUser $user, FlowDraft $draft): bool
    {
        return $user instanceof User
            && $this->isAssignedOrAdmin($user, $draft)
            && ($user->can(Permission::ManageFlowDefinitions->value)
                || $user->can(Permission::ViewFlowSessions->value));
    }

    public function create(AuthUser $user): bool
    {
        return $user instanceof User
            && $user->can(Permission::ManageFlowDefinitions->value);
    }

    public function update(AuthUser $user, FlowDraft $draft): bool
    {
        return $user instanceof User
            && $user->can(Permission::ManageFlowDefinitions->value)
            && $this->isAssignedOrAdmin($user, $draft);
    }

    public function delete(AuthUser $user, FlowDraft $draft): bool
    {
        return $this->update($user, $draft);
    }

    public function publish(AuthUser $user, FlowDraft $draft): bool
    {
        return $user instanceof User
            && $user->can(Permission::PublishFlow->value)
            && $this->isAssignedOrAdmin($user, $draft);
    }

    private function isAssignedOrAdmin(User $user, FlowDraft $draft): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        return $user->assistants()->whereKey($draft->assistant_id)->exists();
    }
}
```

Аналогично для остальных policy — копируем pattern, меняем permission и assignment-check (если ресурс тенант-уровня — проверки на assistant нет, например `TenantTranslationPolicy`).

## Регистрация policy

В domain ServiceProvider'е (или AuthServiceProvider`):

```php
public function boot(): void
{
    Gate::policy(FlowDraft::class, FlowDraftPolicy::class);
    Gate::policy(FlowGroup::class, FlowGroupPolicy::class);
    Gate::policy(FlowSession::class, FlowSessionPolicy::class);
    Gate::policy(FlowLog::class, FlowLogPolicy::class);
    Gate::policy(TenantTranslation::class, TenantTranslationPolicy::class);
    Gate::policy(AssistantTranslation::class, AssistantTranslationPolicy::class);
}
```

Проверить — есть ли `AuthServiceProvider` в проекте. Если нет — лучше регистрировать в соответствующем DomainServiceProvider (например, FlowServiceProvider регистрирует Flow*Policies).

## Page-level canAccess

```php
// app/Filament/Assistant/Pages/TranslationsPage.php
public static function canAccess(): bool
{
    $user = Auth::user();
    if ( ! $user instanceof User) return false;
    if ( ! $user->can(Permission::ManageTranslations->value)) return false;

    // Assistant context — должен быть назначен либо admin
    $assistant = app(CurrentAssistantInterface::class);
    if ( ! $assistant->isResolved()) return false;
    if ($user->isAdmin()) return true;
    return $user->assistants()->whereKey($assistant->get()->getKey())->exists();
}
```

## Обновление Resource классов

После регистрации policies, ресурсы могут использовать стандартные Filament-методы:

```php
// AssistantResource.php
public static function shouldRegisterNavigation(): bool
{
    return static::canViewAny();
}
```

Метод `canViewAny()` Filament сам зовёт `Gate::allows('viewAny', ...)`. Inline проверки `Auth::user()->isAdmin()` (из `AssistantResource::getEloquentQuery`) переписываются через Policy `viewAny` → если admin, query не фильтруется; иначе — `whereHas('users', ...)`. Сама фильтрация остаётся в `getEloquentQuery` (это data scope, отдельно от auth).

## Файлы

Новые:
- `app/Domains/Flow/Policies/FlowDraftPolicy.php`
- `app/Domains/Flow/Policies/FlowGroupPolicy.php`
- `app/Domains/Flow/Policies/FlowSessionPolicy.php`
- `app/Domains/Flow/Policies/FlowLogPolicy.php`
- `app/Domains/Flow/Policies/TenantTranslationPolicy.php`
- `app/Domains/Flow/Policies/AssistantTranslationPolicy.php`
- `tests/Unit/Domains/Flow/Policies/*Test.php` — по одному на каждую policy

Модификации:
- `app/Domains/Flow/Providers/FlowServiceProvider.php` — register Gate::policy для всех flow policies
- `app/Filament/Assistant/Pages/TranslationsPage.php` — `canAccess()` метод
- `app/Filament/Assistant/Pages/AssistantSettings.php` — `canAccess()`
- `app/Filament/Assistant/Pages/AssistantDashboard.php` — `canAccess()`
- `app/Filament/Pages/TranslationsPage.php` (admin) — `canAccess()`
- `app/Filament/Pages/TenantSettingsPage.php` — `canAccess()`

## Тестирование

Каждая Policy покрывается unit-тестом со следующими кейсами:
- Admin → все методы возвращают true
- User с правильным permission + assigned assistant → true (где применимо)
- User с правильным permission, но не assigned → false (для assistant-resources)
- User без permission → false
- Non-User instance (Filament tenant model и т.п.) → false

## Acceptance criteria

- 6 новых policy классов, каждый с unit-тестом (≥4 кейса).
- Все Filament Pages имеют `canAccess()` с явной проверкой permission.
- `php artisan test --compact tests/Unit/Domains/Flow/Policies/` — зелёный.
- Manual smoke: создать non-admin юзера с ролью у которой нет `ViewFlowSessions` → попытка зайти на FlowSessions URL должна вернуть 403.

## Риски

- **Filament Resource auto-discovery** может зарегистрировать Resource в navigation даже без permission'а, если `shouldRegisterNavigation` не переопределён. Проверить каждый ресурс при миграции.

- **`canAccess` vs `shouldRegisterNavigation`** — это два разных метода:
  - `shouldRegisterNavigation` — отображать ли пункт в меню.
  - `canAccess` — пускать ли на URL даже при прямом переходе.
  Оба должны быть согласованы. Иначе можно увидеть пункт в меню, но получить 403 при клике (или наоборот, скрыть из меню, но любой по URL зайдёт).

---

## Связано с

- [[00-overview]] — overview auth
- [[01-phase-permissions-expansion]] — предыдущая фаза
- [[03-phase-migrate-inline-checks]] — следующая фаза
