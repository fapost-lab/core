# Фаза 1 · Permissions expansion + RoleEnum updates

**Длительность:** ~1 день
**Зависит от:** —
**Блокирует:** Фаза 2, Фаза 4

## Цель

Раздробить crucial coarse-grained permissions на гранулярные. Добавить лейблы и описания, чтобы UI Roles формы показывала осмысленные подсказки. Пересинхронизировать системные роли через RoleSeeder.

## Изменения в `Permission` enum

### Новый набор cases

```php
enum Permission: string
{
    // ── Assistants group ────────────────────────────────────────────
    case ManageAssistants        = 'manage_assistants';
    case ManageChannels          = 'manage_channels';            // NEW
    case RotateChannelToken      = 'rotate_channel_token';       // NEW (sensitive)
    case ManageAssistantSettings = 'manage_assistant_settings';  // NEW

    // ── Flow group ──────────────────────────────────────────────────
    case ManageFlow              = 'manage_flow';                // оставлен (deprecated marker)
    case ManageFlowDefinitions   = 'manage_flow_definitions';    // NEW (=create/edit drafts)
    case PublishFlow             = 'publish_flow';               // NEW (sensitive)
    case ViewFlowSessions        = 'view_flow_sessions';         // NEW
    case ManageFlowGroups        = 'manage_flow_groups';         // NEW
    case ManageTranslations      = 'manage_translations';        // NEW

    // ── Users / Roles group ─────────────────────────────────────────
    case ManageUsers             = 'manage_users';
    case ManageRoles             = 'manage_roles';               // NEW (отделить от users)

    // ── Content (broadcast / RAG / media) ───────────────────────────
    case ManageBroadcast         = 'manage_broadcast';
    case ManageRag               = 'manage_rag';
    case ViewMedia               = 'view_media';
    case ManageMedia             = 'manage_media';

    // ── Contacts ────────────────────────────────────────────────────
    case ViewContacts            = 'view_contacts';
    case ManageContacts          = 'manage_contacts';

    // ── Analytics / System ──────────────────────────────────────────
    case ViewAnalytics           = 'view_analytics';
    case ViewSystem              = 'view_system';
    case ManageSettings          = 'manage_settings';            // tenant-level settings

    public function label(): string { /* __('staff.permissions.labels.'.$this->value) */ }
    public function description(): string { /* __('staff.permissions.descriptions.'.$this->value) */ }
    public function isSensitive(): bool                           // NEW (для warning в UI)
    {
        return match ($this) {
            self::RotateChannelToken,
            self::PublishFlow,
            self::ManageRoles => true,
            default            => false,
        };
    }
}
```

### Логика «manage_flow» — deprecation

Старый `Permission::ManageFlow` остаётся, помечается `@deprecated` PHPDoc. Новые проверки в коде должны использовать гранулярные. Старые проверки (`->can('manage_flow')`) продолжают работать пока их не мигрируют (см. Фаза 3).

PHPDoc:
```php
/**
 * @deprecated Use granular flow permissions: ManageFlowDefinitions,
 *             PublishFlow, ViewFlowSessions, ManageFlowGroups,
 *             ManageTranslations.
 */
case ManageFlow = 'manage_flow';
```

### `Permission::group()` — обновление

Добавить новые кейсы в существующие группы:

```php
public function group(): string
{
    return match ($this) {
        self::ManageAssistants,
        self::ManageChannels,
        self::RotateChannelToken,
        self::ManageAssistantSettings    => 'assistants',

        self::ManageUsers,
        self::ManageRoles                => 'users',

        self::ManageFlow,
        self::ManageFlowDefinitions,
        self::PublishFlow,
        self::ViewFlowSessions,
        self::ManageFlowGroups,
        self::ManageTranslations,
        self::ManageBroadcast,
        self::ManageRag,
        self::ViewMedia,
        self::ManageMedia                => 'content',

        self::ViewContacts,
        self::ManageContacts             => 'contacts',

        self::ViewAnalytics              => 'analytics',
        self::ViewSystem,
        self::ManageSettings             => 'system',
    };
}
```

(Решение: flow-related permissions попадают в `content` группу, как и broadcast/rag/media — это всё про контентную работу. Если хочется отдельную «flow» секцию — добавить в `Permission::groupedByGroup()` отдельный ключ, переводы соответственно.)

## Изменения в `RoleEnum`

```php
public function permissions(): array
{
    return match ($this) {
        self::Admin => Permission::cases(),  // как было

        self::ContentManager => [
            // Flow
            Permission::ManageFlowDefinitions,
            Permission::PublishFlow,
            Permission::ViewFlowSessions,
            Permission::ManageFlowGroups,
            Permission::ManageTranslations,
            // Other content
            Permission::ManageBroadcast,
            Permission::ManageRag,
            Permission::ManageMedia,
            Permission::ViewMedia,
            // Contacts
            Permission::ViewContacts,
            // Analytics
            Permission::ViewAnalytics,
        ],

        self::Analyst => [
            Permission::ViewAnalytics,
            Permission::ViewFlowSessions,    // NEW: можно смотреть runtime
            Permission::ViewContacts,        // NEW: для cohort анализа
        ],
    };
}
```

`ManageFlow` (deprecated) **не включается** ни в одну системную роль — новые проверки используют гранулярные. Если у текущего тенанта в БД есть кастомные роли с `manage_flow` — они продолжат работать через legacy code, но при следующем редактировании в UI юзер сможет переключиться на новые гранулярные.

## Translations

Добавить в `lang/{en,ru,uk}/staff.php`:

```php
'permissions' => [
    'labels' => [
        'manage_assistants'         => 'Manage assistants',
        'manage_channels'           => 'Manage channels',
        'rotate_channel_token'      => 'Rotate channel webhook hash',
        'manage_assistant_settings' => 'Edit assistant settings',
        'manage_flow'               => 'Manage flow (legacy — covers all flow ops)',
        'manage_flow_definitions'   => 'Create / edit flow drafts',
        'publish_flow'              => 'Publish flow to live',
        'view_flow_sessions'        => 'View flow sessions / logs',
        'manage_flow_groups'        => 'Manage flow groups',
        'manage_translations'       => 'Edit translations',
        // ... etc
    ],
    'descriptions' => [
        'rotate_channel_token'      => 'Reissues webhook URL hash. Existing webhooks become invalid until re-configured.',
        'publish_flow'              => 'Promotes a draft to live; affects all incoming sessions immediately.',
        'manage_roles'              => 'Create and edit custom roles with their permission sets.',
        // ... etc
    ],
    'sensitive_warning'             => 'Sensitive permission — review carefully before assigning.',
],
```

## RoleSeeder — без изменений

Текущий `RoleSeeder::run()`:
1. Создаёт каждый `Permission::cases()` через `Permission::firstOrCreate(['name' => $case->value])` — новые case'ы автоматически появятся в БД.
2. Для каждой `RoleEnum::cases()` делает `Role::updateOrCreate` + `syncPermissions(...)` — системные роли пересинхронизируются с обновлёнными наборами.

Re-run RoleSeeder при следующем `platform:install` / `ops:tenants-migrate --seed` (или вручную) — миграция данных не нужна.

## Файлы

| Файл | Изменения |
|------|-----------|
| `app/Domains/Staff/Enums/Permission.php` | +9 cases, deprecation на ManageFlow, +3 method (`label`, `description`, `isSensitive`), обновлён `group()` |
| `app/Domains/Staff/Enums/RoleEnum.php` | переписан `permissions()` для всех 3 системных ролей |
| `lang/en/staff.php` | + блок `permissions.labels` и `permissions.descriptions` |
| `lang/ru/staff.php` | то же |
| `lang/uk/staff.php` | то же |
| `tests/Unit/Domains/Staff/PermissionEnumTest.php` (новый) | unit тесты на `group()` распределение, `isSensitive()` set, что все permissions имеют label/description |
| `tests/Feature/Domains/Staff/RoleSeederTest.php` | дополнить: после re-run новые permissions присутствуют, системные роли имеют их |

## Acceptance criteria

- `Permission::values()` возвращает 21 значение (было 12).
- `Permission::groupedByGroup()` возвращает 6 групп (assistants/users/content/contacts/analytics/system).
- Re-run RoleSeeder на существующем тенанте: новые permissions созданы, системные роли пересинхронизированы.
- Unit тест: каждый `Permission` case имеет непустой `label()` (отсутствие в lang файлах ловится).
- Unit тест: `RoleEnum::Admin->permissions()` содержит все cases.
- Unit тест: `RoleEnum::ContentManager->permissions()` НЕ содержит `RotateChannelToken`, `PublishFlow` стоит явно.

## Риски

- **Перевод старых проверок** на новые permissions — пока этого не сделано, система продолжит работать на `manage_flow` (legacy). Это не баг, но нужно проследить чтобы Фаза 3 завершилась раньше чем кто-то снимет `manage_flow` с системной роли в UI (что сделает пользователя без прав).

- **Кастомные роли с manage_flow** — после развёртывания они продолжат иметь `manage_flow`; чтобы получить нативные новые permissions (например, чтобы юзер мог публиковать flow если у него только `manage_flow` и в коде уже стоит проверка `publish_flow`) — требуется ручная миграция в UI или одноразовый скрипт. Документировать в release notes.

---

## Связано с

- [[00-overview]] — overview auth
- [[02-phase-policy-classes]] — следующая фаза
