# Assistant Panel (Filament)

> Архив Notion. Актуальная документация: [[04-assistant-domain]]


Depends on: 06b
Domain: Assistant
Phase: 1 — Core Platform
Sprint: 3
Status: Готово
Task №: 6.2

## Контекст

Assistant — больше не просто CRUD-сущность. Нужна отдельная Filament panel для операционного управления конкретным ассистентом. Главная admin panel остаётся platform-level. Assistant panel — изолированный UI-контекст одного ассистента внутри текущего тенанта.

> Это НЕ tenant separation. Тенант уже резолвлен. Assistant panel работает поверх него.
> 

---

## Архитектура

### Два panel

| Panel | Prefix | Назначение |
| --- | --- | --- |
| **AdminPanel** | `/admin` | Users, Assistants list, Roles, platform admin |
| **AssistantPanel** | `/assistant` | Операционное управление конкретным ассистентом |

### Резолвинг assistant в AssistantPanel

Вход через action «Manage» в `AssistantResource`:

1. Записывает `assistant_id` в сессию (`assistant.current_id`)
2. Редирект на `/assistant?assistant={uuid}`

`ResolveAssistantMiddleware` при каждом запросе в `/assistant/*`:

1. Если в запросе есть `?assistant={uuid}` → валидирует, авторизует, пишет в сессию и в `CurrentAssistant`
2. Если param отсутствует → читает `assistant_id` из сессии → пишет в `CurrentAssistant`
3. Если ни там ни там нет → redirect на `/admin`

> Первый вход через query param, дальше сессия. Ссылки внутри panel работают без param — Filament их не пробрасывает.
> 

---

## Что реализовать

### 1. CurrentAssistant (scoped)

```
app/Domains/Assistant/Services/CurrentAssistant.php
```

```php
interface CurrentAssistantInterface
{
    public function set(Assistant $assistant): void;
    public function get(): Assistant; // throws AssistantNotResolvedException
    public function isResolved(): bool;
}
```

Регистрация: `scoped` binding (критично для Octane). Не singleton.

### 2. ResolveAssistantMiddleware

```
app/Http/Middleware/ResolveAssistantMiddleware.php
```

Ответственности:

- Query param `?assistant=uuid` (приоритет) или сессия `assistant.current_id`
- Admin → видит любой ассистент тенанта; обычный user — только через `user_assistants`
- Недоступ → redirect `/admin` с flash-сообщением
- Пишет в `CurrentAssistantInterface`

> Не дублировать tenant middleware. `TenantMiddleware` выполняется до этого.
> 

### 3. AssistantPanelProvider

```
app/Providers/Filament/AssistantPanelProvider.php
```

- Panel ID: `assistant`, path: `assistant`
- Middleware порядок (явный): `[web, TenantMiddleware, auth, ResolveAssistantMiddleware]`
- Navigation независима от admin panel

### 4. user_assistants pivot

Миграция если не создана в 06b:

```sql
CREATE TABLE user_assistants (
    user_id       UUID NOT NULL,
    assistant_id  UUID NOT NULL REFERENCES assistants(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, assistant_id)
);
```

### 5. AssistantPolicy

```
app/Domains/Assistant/Policies/AssistantPolicy.php
```

`view()`: admin → true, другой user → `user_assistants` проверка.

### 6. Action «Управлять» в AssistantResource

В `AssistantResource` (admin panel) добавить `TableAction`:

- Записывает `assistant_id` в сессию
- Redirect на `/assistant?assistant={id}`

### 7. AssistantDashboard

```
app/Filament/AssistantPanel/Pages/AssistantDashboard.php
```

Содержимое: имя ассистента, список channels, placeholders Flows/Settings.

### 8. ChannelResource внутри AssistantPanel

```
app/Filament/AssistantPanel/Resources/ChannelResource.php
```

- Scope: `Channel::where('assistant_id', app(CurrentAssistantInterface::class)->get()->id)`
- Бизнес-логика через существующий `ChannelService`
- Action «Ротировать webhook hash» с modal-подтверждением

---

## Критические риски

| Риск | Последствие | Решение |
| --- | --- | --- |
| `CurrentAssistant` как singleton | Состояние течёт между запросами в Octane | `scoped` binding — обязательно |
| Query param теряется при навигации | Пользователь попадает на стр. без ассистента | Middleware читает из сессии при отсутствии param |
| Неправильный порядок middleware | `TenantContext::get()` бросает внутри ResolveAssistant | Явный порядок в provider: `TenantMiddleware` до |

---

## Тесты (PHPUnit)

`tests/Feature/AssistantPanel/`

- `test_admin_can_access_any_assistant`
- `test_user_cannot_access_unassigned_assistant`
- `test_assistant_resolved_from_session_after_initial_query_param`
- `test_missing_assistant_redirects_to_admin`
- `test_current_assistant_is_scoped_per_request`
- `test_channel_resource_scoped_to_current_assistant`
- `test_rotate_webhook_hash_action`

---

## Порядок реализации

1. `CurrentAssistantInterface` + `CurrentAssistant` + scoped binding
2. `ResolveAssistantMiddleware`
3. `AssistantPanelProvider`
4. `user_assistants` pivot (миграция)
5. `AssistantPolicy`
6. Action «Управлять» в `AssistantResource`
7. `AssistantDashboard`
8. `ChannelResource` внутри panel
9. Тесты

---

## Зависимости

- 06b — Assistant & Channel Domain (модели, сервисы, таблицы `assistants` + `channels`, `user_assistants` pivot)
- 05 — Staff Domain (пользователи, permission система)

---

## Ревю (март 2026)

> ⚠️ 3 замечания, нужно устранить до закрытия
> 

**1. Отсутствует тест на re-activate канала**

В 06 был `test_reactivate_writes_back_to_redis`. В `ChannelServiceTest` его нет. Добавить: `test_reactivate_channel_writes_back_to_redis`.

**2. Middleware порядок — `auth` не объяснён**

Poryadok `[web, TenantMiddleware, auth, ResolveAssistantMiddleware]` правильный, но не объяснён почему `auth` обязателен до `ResolveAssistant`. Без `auth` — `Auth::user()` == null, `AssistantPolicy::view()` выбрасывает `AuthorizationException` вместо redirect на login. Добавить примечание в provider. Добавить тест: `test_unauthenticated_user_redirected_to_login_not_to_admin`.

**3. `AssistantDashboard` — не указано как home page панели**

Filament v5 требует явной регистрации home page. Добавить в `AssistantPanelProvider`: `->homePage(AssistantDashboard::class)`. Без этого переход на `/assistant` может дать 404 в Filament v5.

**4. [NEW] `CurrentAssistantInterface` — путь не соответствует соглашениям**

Указан `app/Domains/Assistant/Services/CurrentAssistant.php`. По соглашениям [CLAUDE.md](http://CLAUDE.md) интерфейс живёт в `Contracts/`. Исправить: `CurrentAssistantInterface` → `app/Domains/Assistant/Contracts/CurrentAssistantInterface.php`, реализация → `app/Domains/Assistant/Services/CurrentAssistant.php`.

**5. [NEW] `app()` внутри `ChannelResource` scope — антипаттерн**

В задаче написано: `Channel::where('assistant_id', app(CurrentAssistantInterface::class)->get()->id)`. Использование `app()` внутри scope противоречит [CLAUDE.md](http://CLAUDE.md): "запрещено `app()` внутри доменного кода — только через DI". Исправить: инжектировать `CurrentAssistantInterface` через Filament lifecycle (например, через `boot()` или Filament `mount()` в resource), а не через service locator.