# Фаза 4 · Filament Roles UI — descriptions, grouping, sensitive warnings

**Длительность:** ~0.5 дня
**Зависит от:** Фаза 1 (новые permissions с label/description/isSensitive методами)
**Блокирует:** —

## Цель

`RoleResource` уже существует и позволяет создавать кастомные роли через UI. Расширить форму так, чтобы:
- Permissions сгруппированы по `Permission::group()` визуально (Section per group).
- Каждая permission показана с label + description под чекбоксом.
- Sensitive permissions (`RotateChannelToken`, `PublishFlow`, `ManageRoles`) выделены визуально + предупреждение при выборе.
- Priority поля имеет валидацию: для кастомных ролей должно быть строго ниже priority роли создателя (уже есть в `RoleWriterService`, перенести в form-level validation для UX feedback до submit).

## Текущее состояние формы

`app/Filament/Resources/Roles/Schemas/RoleFormSchema.php` (или близко по имени) — рендерит поля `name`, `display_name`, `permissions` (CheckboxList), `priority`. Permissions отображаются плоским списком из `Permission::values()`.

## Что меняем

### 4.1 Permissions — группированный CheckboxList

Filament `CheckboxList` принимает `->options()` как map `[value => label]`. Чтобы группировать — нужно либо:
- Собрать несколько `CheckboxList` (по одному на группу) внутри `Section`'ов
- Использовать кастомный component с group support

Рекомендация: первый вариант — стандартные Filament-сечения, понятный код:

```php
$groups = Permission::groupedByGroup();  // [group => Permission[]]

$sections = [];
foreach ($groups as $groupKey => $permissions) {
    $sections[] = Section::make(__("staff.permission_groups.{$groupKey}"))
        ->collapsible()
        ->schema([
            CheckboxList::make("permissions_{$groupKey}")
                ->hiddenLabel()
                ->options(self::permissionOptions($permissions))
                ->descriptions(self::permissionDescriptions($permissions))
                ->columns(1)
                ->dehydrated(false),  // не сохраняется напрямую — собирается в общий permissions ниже
        ]);
}

// Hidden field, собирающий все permissions из всех групп
Hidden::make('permissions')
    ->dehydrateStateUsing(fn (Get $get): array => self::collectPermissions($get));
```

Helper'ы:

```php
private static function permissionOptions(array $permissions): array
{
    $options = [];
    foreach ($permissions as $perm) {
        $label = __("staff.permissions.labels.{$perm->value}");
        if ($perm->isSensitive()) {
            $label = '⚠ ' . $label;
        }
        $options[$perm->value] = $label;
    }
    return $options;
}

private static function permissionDescriptions(array $permissions): array
{
    $descriptions = [];
    foreach ($permissions as $perm) {
        $descriptions[$perm->value] = __("staff.permissions.descriptions.{$perm->value}");
    }
    return $descriptions;
}
```

### 4.2 Sensitive permissions warning

Filament CheckboxList не имеет built-in confirm-on-check. Альтернатива: при сохранении формы (на `mutateFormDataBeforeSave`) проверить — если выбраны sensitive permissions и роль новая → показать confirm modal через Filament Action или просто Notification с информационным текстом.

Минимально-достаточный вариант:
- Иконка ⚠ перед label sensitive permission (визуально выделено)
- Description под permission объясняет последствия (`Reissues webhook URL hash...`)
- Без интерактивного confirm — описание само по себе предупреждает.

### 4.3 Priority — form-level validation

Сейчас `RoleWriterService::validatePriority` бросает исключение если priority кастомной роли ≥ priority текущего юзера. Это работает, но юзер видит ошибку только после submit.

Добавить inline rule в форме:

```php
TextInput::make('priority')
    ->numeric()
    ->minValue(1)
    ->maxValue(static fn (): int => self::maxAllowedPriority())
    ->helperText(__('staff.roles.priority_hint', [
        'max' => self::maxAllowedPriority(),
    ]));

private static function maxAllowedPriority(): int
{
    $actor = Auth::user();
    if ($actor instanceof User && $actor->isAdmin()) {
        return 99;  // строго ниже Admin = 100
    }
    if ($actor instanceof User) {
        $highest = $actor->roles()->max('priority') ?? 0;
        return max(0, $highest - 1);
    }
    return 0;
}
```

### 4.4 System role — read-only режим

`Role::isSystemRole()` уже определяет что роль системная (Admin / ContentManager / Analyst). UI должен:
- Disable name field (нельзя переименовать)
- Disable priority field (фиксирован в RoleEnum)
- Permissions field — показать но disable (всё хардкодом в RoleEnum)
- Скрыть Delete action

Это уже частично есть через `RolePolicy::update/delete` — добавить визуальную disable семантику в форму:

```php
TextInput::make('name')
    ->disabled(fn (?Role $record): bool => $record?->isSystemRole() ?? false);
```

## Файлы

Модификации:
- `app/Filament/Resources/Roles/Schemas/RoleFormSchema.php` — переписан (группированные секции + descriptions + sensitive icons)
- `app/Filament/Resources/Roles/Pages/CreateRole.php` / `EditRole.php` — handle `permissions_{group}` → собирать в `permissions` через mutator
- `lang/en/staff.php` — `permission_groups.*` секционные заголовки + `permissions.descriptions` (если ещё не из Фазы 1)
- то же для ru/uk
- `app/Domains/Staff/Services/RoleWriterService.php` — никаких изменений (validate priority остаётся как safety-net)

## Acceptance criteria

- В форме создания роли permissions сгруппированы (6 секций: assistants, users, content, contacts, analytics, system).
- Под каждой permission видно её description (compact, 1-2 строки).
- Sensitive permissions помечены ⚠ + описание объясняет последствия.
- Priority field имеет maxValue по текущему юзеру.
- Системная роль (Admin) открывается на edit с disabled полями.
- Создание custom роли с granular set permissions сохраняется и работает.

## Out of scope

- Drag-reorder permissions внутри группы (V1.x — нет реальной нужды)
- Custom permission создание через UI (всегда из enum, через код)
- Bulk-clone роли (V1.x)
- Audit-log entry при изменении роли (отдельная задача про audit_log)

---

## Связано с

- [[00-overview]] — overview auth
- [[03-phase-migrate-inline-checks]] — предыдущая фаза
- [[03-staff-users]] — Staff UI
