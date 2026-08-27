# Assistant & Channel Domain

> Архив Notion. Актуальная документация: [[04-assistant-domain]]


Depends on: 04, supersedes 06
Domain: Assistant
Phase: 1 — Core Platform
Sprint: 3
Status: Готово
Task №: 6.1

## Контекст

Архитектурное решение принято в марте 2026: `Bot` как верхний доменный термин упраздняется. `Assistant` становится верхним бизнес-агрегатом. `Channel` — подчинённая transport-сущность.

Задача 06 (Bot Domain) реализована частично — самое время внести правки до углубления в реализацию.

> Референс: **Assistant Domain — Architectural Model** (страница в «Архитектура платформы»)
> 

---

## Что меняется относительно задачи 06

| Было (задача 06) | Стало (задача 06b) |
| --- | --- |
| Таблица `bots` | Таблицы `assistants`  • `channels` |
| Модель `Bot` | Модели `Assistant`  • `Channel` |
| `BotService` | `AssistantService`  • `ChannelService` |
| Redis: `bot_id` в payload | Redis: `assistant_id`  • `channel_id` |
| `session_lock:{tenant}:{contact}:{bot}` | `session_lock:{tenant}:{contact}:{assistant}` |
| `webhook:{public_hash}` → `{bot_id}` | `webhook:{public_hash}` → `{assistant_id, channel_id}` |
| `default_flow_id` на боте | `default_flow_id` на ассистенте |
| `fallback_message` на боте | `fallback_message` на ассистенте |

---

## Миграции

### assistants

```sql
CREATE TABLE assistants (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id           UUID NOT NULL,
    name                VARCHAR(255) NOT NULL,
    is_active           BOOLEAN NOT NULL DEFAULT true,
    default_flow_id     UUID NULL,
    fallback_message    TEXT NULL,
    settings            JSONB NOT NULL DEFAULT '{}',
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

### channels

```sql
CREATE TABLE channels (
    id                   UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    assistant_id         UUID NOT NULL REFERENCES assistants(id) ON DELETE CASCADE,
    tenant_id            UUID NOT NULL,
    type                 VARCHAR(20) NOT NULL,  -- 'telegram' | 'whatsapp'
    token                TEXT NOT NULL,         -- encrypted
    secret_token         TEXT NOT NULL,         -- encrypted
    webhook_public_hash  VARCHAR(64) NOT NULL UNIQUE,
    config               JSONB NOT NULL DEFAULT '{}',
    is_active            BOOLEAN NOT NULL DEFAULT true,
    created_at           TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

> ⚠ `default_flow_id` — nullable без FK constraint на этом этапе. FK добавить в задаче 09 когда `flow_definitions` будет создана.
> 

---

## Модели

### Assistant

```php
// app/Domains/Assistant/Models/Assistant.php

protected $casts = [
    'settings'  => 'array',
    'is_active' => 'boolean',
];

public function channels(): HasMany
{
    return $this->hasMany(Channel::class);
}
```

### Channel

```php
// app/Domains/Assistant/Models/Channel.php

protected $casts = [
    'type'         => ChannelTypeEnum::class,
    'token'        => 'encrypted',
    'secret_token' => 'encrypted',
    'config'       => 'array',
    'is_active'    => 'boolean',
];

public function assistant(): BelongsTo
{
    return $this->belongsTo(Assistant::class);
}
```

**Инварианты Channel:**

- `webhook_public_hash` генерируется один раз при создании
- Ротация — только через `ChannelService::rotateWebhookHash()`
- `webhook_public_hash` не меняется при update токена

---

## ChannelTypeEnum

```php
// app/Domains/Assistant/Enums/ChannelTypeEnum.php

enum ChannelTypeEnum: string
{
    case Telegram = 'telegram';
    case WhatsApp = 'whatsapp';
}
```

---

## AssistantService / ChannelService

```php
interface AssistantServiceInterface
{
    public function create(TenantInterface $tenant, array $data): Assistant;
    public function update(Assistant $assistant, array $data): Assistant;
    public function activate(Assistant $assistant): void;
    public function deactivate(Assistant $assistant): void;
}

interface ChannelServiceInterface
{
    public function create(Assistant $assistant, array $data): Channel;
    public function update(Channel $channel, array $data): Channel;
    public function rotateWebhookHash(Channel $channel): Channel;
    public function deactivate(Channel $channel): void;
    public function reactivate(Channel $channel): void;
}
```

---

## Redis Webhook Registry

**Ключ:** `{REDIS_PREFIX}:webhook:{public_hash}`

**Значение (JSON):**

```json
{
    "tenant_id": "uuid",
    "assistant_id": "uuid",
    "channel_id": "uuid",
    "channel": "telegram",
    "secret_token": "plain_secret"
}
```

**Контракт write-through:**

- БД — источник истины
- Redis — hot cache, TTL не ставим
- `platform:install` прогревает registry: `Channel::active()->with('assistant')->each(fn($c) => $registry->set($c))`

---

## Runtime Flow

```
Incoming webhook
  → resolve channel (by public_hash из Redis)
  → resolve assistant (из channel payload)
  → run assistant flow
```

---

## Incoming message lock

Distributed lock ключ меняется:

```
session_lock:{tenant_id}:{contact_id}:{assistant_id}
```

(было: `{bot_id}`, теперь `{assistant_id}` — изоляция по ассистенту, не по каналу)

---

## Filament UI

**Главная панель:**

- Раздел «Ассистенты» — список `Assistant` tenant
- CRUD: create, edit, view, delete
- Права: `manage_assistants`

**Assistant Management Panel (nested):**

- Открывается при «Управлять» на конкретном ассистенте
- Внутри: Каналы, Flows, Sessions, Logs

**Каналы (внутри ассистента):**

- CRUD каналов
- Action «Ротировать webhook hash» с модальным подтверждением
- Поля: `type` (select), `token` (password), `secret_token` (password), `is_active` (toggle), `config` (JSON editor)

---

## Access Control (User ↔ Assistants)

Many-to-many: `user_assistants` pivot.

Пользователь видит только назначенные ему ассистенты — влияет на:

- список в главной панели
- assistant management panel
- выбор ассистента в триггерах
- выбор ассистента в рассылках

---

## Тесты (PHPUnit)

`tests/Unit/Domains/Assistant/`

- `AssistantServiceTest`
    - `test_create_assistant`
    - `test_deactivate_assistant_deactivates_channels`
- `ChannelServiceTest`
    - `test_create_generates_webhook_hash`
    - `test_create_writes_to_redis_registry`
    - `test_update_does_not_change_webhook_hash`
    - `test_update_overwrites_redis_entry`
    - `test_rotate_hash_generates_new_hash`
    - `test_rotate_hash_updates_redis`
    - `test_deactivate_removes_from_redis`

---

## Порядок реализации

1. Дроп/rename старой миграции `bots` (если не в проде — просто заменить)
2. Миграция `assistants`
3. Миграция `channels`

3a. Миграция `user_assistants` (ответственность 06b)

1. `ChannelTypeEnum`
2. `Assistant` модель
3. `Channel` модель с castами
4. `ChannelWebhookRegistry` — обёртка над Redis (обновить payload)
5. `AssistantService` + `ChannelService`
6. `platform:install` — warmup registry
7. Filament: `app/Filament/Resources/Assistants/AssistantResource.php` + nested `ChannelResource`
8. Тесты

---

## Зависимости

- Задача 04 (TenancyMiddleware & boot) — `TenantInterface`, Redis connection
- Задача 06b является блокером для задачи 08 (Webhook routing — обновить payload)