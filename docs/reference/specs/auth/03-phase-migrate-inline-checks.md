# Фаза 3 · Миграция inline проверок на единый Policy/Gate стиль

**Длительность:** ~0.5 дня
**Зависит от:** Фаза 2 (policy классы существуют для всех ресурсов)
**Блокирует:** —

## Цель

Заменить 11 точечных inline auth-проверок в Filament на единый стиль через Gate / Policy. Удалить дублирование проверок, которые уже инкапсулированы в policy.

## Аудит текущих проверок

Перечень точек где сейчас auth (после Фазы 2 часть из них уже обновлена через `canAccess()`):

| Файл | Строка | Текущий код | Действие |
|------|--------|-------------|----------|
| `AssistantResource.php` | `getEloquentQuery` | `if ($user->isAdmin()) return $query;` | оставить (это data scope, не auth check) |
| `AssistantResource.php` | `getEloquentQuery` | `whereHas('users', ...)` | оставить (data scope) |
| `AssistantsTable.php` | row action | `Gate::allows('view', $record)` | уже правильно |
| `Assistants/Pages/CreateAssistant.php` | `mount` | `if ( ! $user->isAdmin())` | заменить на `Gate::authorize('create', Assistant::class)` |
| `Roles/RoleResource.php` | `shouldRegisterNavigation` | `$user->can('viewAny', Role::class)` | оставить (это уже Gate-style) |
| `Roles/Pages/CreateRole.php` | `handleRecordCreation` | `Auth::user()` для actor | оставить (нужен actor для service, не auth check) |
| `Users/Tables/UsersTable.php` | actions visible | `$actor->can('resendActivation', $record)` | оставить (Gate-style) |
| `Users/Schemas/UserForm.php` | поле visible | `$actor->can('updateRoles', $record)` | оставить |
| `Users/Pages/EditUser.php` | post-save | `Gate::forUser($actor)->authorize('updateRoles', ...)` | оставить |
| `Media/MediaResource.php` | `shouldRegisterNavigation` | `$user->can(Permission::ViewMedia->value)` | оставить (это правильный способ для navigation) |
| `Channels/ChannelResource.php` | `canCreate` | `Gate::allows('create', [Channel::class, $tenant])` | оставить |
| `Channels/Tables/ChannelsTable.php` | row action | `Gate::authorize('rotateWebhook', $record)` | заменить permission на новый `RotateChannelToken` |
| `Channels/Pages/CreateChannel.php` | `mount` | `Gate::authorize('create', [...])` | оставить |

## Реальные изменения

После аудита оказывается что большая часть проверок уже выглядит ок. Реально фокусные правки:

### 3.1 `CreateAssistant.php` — заменить isAdmin на Policy

```php
// Было:
public function mount(): void
{
    parent::mount();
    $user = Auth::user();
    if ($user instanceof User && ! $user->isAdmin()) {
        abort(403);
    }
}

// Стало:
public function mount(): void
{
    parent::mount();
    Gate::authorize('create', Assistant::class);
}
```

`AssistantPolicy::create()` уже проверяет `Permission::ManageAssistants` — но возможно нужно дополнительно проверить admin-only flag (см. ниже).

### 3.2 Решить вопрос «admin-only create» для Assistant

Сейчас `AssistantPolicy::create()` пропускает любого с `ManageAssistants`, а `CreateAssistant::mount` дополнительно требует `isAdmin()`. Это рассинхронизация.

Варианты:
- **A** — оставить admin-only через policy: `AssistantPolicy::create` сам проверяет `$user->isAdmin()` (не permission). Тогда снять `ManageAssistants` permission смысла нет, она избыточна.
- **B** — открыть create для всех с `ManageAssistants`, убрать `isAdmin()` check. Теоретически любой content manager сможет создать ассистента. Это меняет policy безопасности.
- **C** — добавить отдельный permission `CreateAssistant` (sensitive), требовать его дополнительно.

Рекомендация: **A** — assistant — bootstrap-уровень сущность, её количество стабильно после первоначальной настройки тенанта. `Permission::ManageAssistants` остаётся для list/view/edit/delete; create — admin-only через явный `isAdmin()` в policy.

### 3.3 Channel rotation — новый permission

```php
// ChannelPolicy::rotateWebhook (текущий)
public function rotateWebhook(AuthUser $user, Channel $channel): bool
{
    return $user instanceof User && $user->can(Permission::ManageAssistants->value);
}

// Новое:
public function rotateWebhook(AuthUser $user, Channel $channel): bool
{
    return $user instanceof User
        && $user->can(Permission::RotateChannelToken->value)
        && $this->isAssignedOrAdmin($user, $channel);
}
```

Тут уже выделили `RotateChannelToken` как sensitive permission — пользователь с обычным `ManageChannels` (создавать/редактировать) НЕ может ротировать токен, нужен отдельный grant.

### 3.4 Flow / Translations / FlowSession — wired through Policies (уже сделано в Фазе 2)

После Фазы 2 в этих ресурсах уже работают policies. Inline проверок там нет (текущие resources либо не имеют policy вообще, либо только через `getEloquentQuery`).

## Проверка через CI

Добавить phpstan-правило (или review checklist) которое запрещает прямые `Auth::user()->isAdmin()` checks вне domain code. Минимум — grep в pre-commit:

```bash
# Должен быть пустой
grep -rE 'isAdmin\(\)' app/Filament --include='*.php' | grep -v 'getEloquentQuery'
```

`isAdmin()` остаётся легитимным **внутри policies** (как часть logic для bootstrap-сценариев) и в `getEloquentQuery` (data scope). Везде ещё — не должен встречаться.

## Файлы

Модификации:
- `app/Filament/Resources/Assistants/Pages/CreateAssistant.php` — заменить isAdmin check на Gate::authorize
- `app/Domains/Channels/Policies/ChannelPolicy.php` — заменить permission на `RotateChannelToken` для `rotateWebhook`
- `app/Domains/Assistant/Policies/AssistantPolicy.php` — добавить admin-only check в `create()` если выбрано вариант A

Возможные:
- pre-commit hook / phpstan rule

## Acceptance criteria

- `grep 'isAdmin()' app/Filament` возвращает только occurrences внутри `getEloquentQuery` методов (data scope).
- `php artisan test --compact` проходит.
- Manual smoke: юзер с `ManageChannels`, но без `RotateChannelToken` → не видит row action «Rotate webhook». Юзер с обоими — видит и может выполнить.

## Риски

- **Backward compat для текущих юзеров** — если у текущего тенанта есть кастомные роли с `ManageAssistants` без `RotateChannelToken`, после миграции они потеряют возможность ротировать webhooks. Это намеренно (прежний `ManageAssistants` был too-coarse), но нужно предупредить в release notes.

---

## Связано с

- [[00-overview]] — overview auth
- [[02-phase-policy-classes]] — предыдущая фаза
- [[04-phase-roles-ui]] — следующая фаза
