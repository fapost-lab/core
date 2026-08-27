# Contact Domain

> Архив Notion. Актуальная документация: [[05-contacts]]


Depends on: 04, 06b
Domain: Contact
Phase: 1 — Core Platform
Sprint: 3
Status: Готово
Task №: 7

## Цель

Построить Contact Domain: canonical identity контакта на уровне tenant и delivery endpoint на уровне channel. Задача является блокером для задачи 08 (Webhook routing) — `findOrCreate` вызывается при каждом входящем сообщении.

> Референс: ADR-03 — ID Strategy (ULID as PK)
> 

---

## Ключевые архитектурные решения

**Contact = identity (кто)**

Contact создаётся один раз на уровне tenant. Один и тот же человек в Telegram — один Contact, независимо от количества assistants и channels.

**ChannelContact = delivery permission (через что можно слать)**

Запись в `channel_contacts` означает: этот contact взаимодействовал с этим channel и боту разрешено ему писать. Для Telegram это критично — бот не может начать диалог первым без предшествующего контакта.

**Tenant owns contacts, Channel owns delivery permission**

Assistant не владеет контактами. Contact принадлежит tenant-у и может использоваться в нескольких assistants/channels.

**platform ≠ channel**

`platform` — тип внешней системы где существует identity (telegram, whatsapp, email). `channel` — конкретный настроенный endpoint внутри assistant. Два Telegram-бота = два `channel`, один `platform = telegram`.

---

## Схема

### contacts

```sql
CREATE TABLE contacts (
    id                  UUID PRIMARY KEY,           -- ULID via HasUlidPrimaryKey
    tenant_id           UUID NOT NULL,
    platform            VARCHAR(20) NOT NULL,        -- PlatformEnum: 'telegram' | 'whatsapp' | 'email'
    external_id         VARCHAR(255) NOT NULL,       -- platform user id / phone / email
    meta                JSONB NOT NULL DEFAULT '{}', -- name, username, avatar, etc.
    attributes          JSONB NOT NULL DEFAULT '{}', -- forward-compatible, see note below
    created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT contacts_tenant_platform_external_unique
        UNIQUE (tenant_id, platform, external_id)
);

CREATE INDEX contacts_tenant_id_idx ON contacts (tenant_id);
```

> ⚠ `attributes` — forward-compatible JSON field. Колонка создаётся сейчас, семантическая запись начинается в задаче 13 когда flow engine вводит ноды `set_attribute` / `input`. На текущем этапе поле остаётся пустым.
> 

> ✕ `contacts.attributes` — НЕ кеш модульных данных. Только данные собранные платформенными нодами. Модули не пишут сюда напрямую.
> 

### channel_contacts

```sql
CREATE TABLE channel_contacts (
    id                    UUID PRIMARY KEY,         -- ULID via HasUlidPrimaryKey
    contact_id            UUID NOT NULL,
    channel_id            UUID NOT NULL,
    last_interaction_at   TIMESTAMPTZ NULL,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),

    CONSTRAINT channel_contacts_contact_channel_unique
        UNIQUE (contact_id, channel_id),

    CONSTRAINT channel_contacts_contact_fk
        FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,

    CONSTRAINT channel_contacts_channel_fk
        FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
);
```

> `last_interaction_at` обновляется при каждом входящем сообщении — implicit delivery permission. Запись есть = бот может писать.
> 

---

## PlatformEnum

```php
// app/Domains/Contact/Enums/PlatformEnum.php

namespace App\Domains\Contact\Enums;

enum PlatformEnum: string
{
    case Telegram  = 'telegram';
    case WhatsApp  = 'whatsapp';
    case Email     = 'email';
}
```

> Намеренно отдельный enum от `ChannelTypeEnum` — семантически разные концепты, будут расходиться (email появится в platform раньше чем в channel).
> 

---

## Модели

### Contact

```php
// app/Domains/Contact/Models/Contact.php

namespace App\Domains\Contact\Models;

use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use App\Domains\Contact\Enums\PlatformEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasUlidPrimaryKey;

    protected $fillable = [
        'tenant_id',
        'platform',
        'external_id',
        'meta',
        'attributes',
    ];

    protected $casts = [
        'platform'   => PlatformEnum::class,
        'meta'       => 'array',
        'attributes' => 'array',
    ];

    public function channelContacts(): HasMany
    {
        return $this->hasMany(ChannelContact::class);
    }
}
```

### ChannelContact

```php
// app/Domains/Contact/Models/ChannelContact.php

namespace App\Domains\Contact\Models;

use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use App\Domains\Assistant\Models\Channel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelContact extends Model
{
    use HasUlidPrimaryKey;

    protected $fillable = [
        'contact_id',
        'channel_id',
        'last_interaction_at',
    ];

    protected $casts = [
        'last_interaction_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
```

---

## ContactService

```php
// app/Domains/Contact/Contracts/ContactServiceInterface.php

interface ContactServiceInterface
{
    /**
     * Найти или создать Contact по tenant + platform + external_id.
     * Idempotent — безопасно вызывать при каждом входящем сообщении.
     */
    public function findOrCreate(
        string $tenantId,
        PlatformEnum $platform,
        string $externalId,
        array $meta = []
    ): Contact;

    /**
     * Найти или создать ChannelContact.
     * Обновляет last_interaction_at при каждом вызове.
     */
    public function findOrCreateChannelContact(
        Contact $contact,
        string $channelId
    ): ChannelContact;
}
```

```php
// app/Domains/Contact/Services/ContactService.php

class ContactService implements ContactServiceInterface
{
    public function findOrCreate(
        string $tenantId,
        PlatformEnum $platform,
        string $externalId,
        array $meta = []
    ): Contact {
        return Contact::firstOrCreate(
            [
                'tenant_id'   => $tenantId,
                'platform'    => $platform,
                'external_id' => $externalId,
            ],
            ['meta' => $meta]
        );
    }

    public function findOrCreateChannelContact(
        Contact $contact,
        string $channelId
    ): ChannelContact {
        $channelContact = ChannelContact::firstOrCreate(
            [
                'contact_id' => $contact->id,
                'channel_id' => $channelId,
            ]
        );

        $channelContact->update(['last_interaction_at' => now()]);

        return $channelContact->refresh();
    }
}
```

> ⚠ `firstOrCreate` в PostgreSQL при concurrent insert может выбросить unique violation. Для горячего пути (задача 08) обернуть в `try/catch` с повторным `firstOrCreate` или использовать `upsert`. Детали — в задаче 08.
> 

---

## Структура файлов

```
app/Domains/Contact/
├── Contracts/
│   └── ContactServiceInterface.php
├── Enums/
│   └── PlatformEnum.php
├── Models/
│   ├── Contact.php
│   └── ChannelContact.php
├── Services/
│   └── ContactService.php
└── Providers/
    └── ContactServiceProvider.php

database/migrations/tenant/
├── xxxx_create_contacts_table.php
└── xxxx_create_channel_contacts_table.php
```

---

## Что НЕ делать

- ✕ Не добавлять `assistant_id` в `contacts` — contact принадлежит tenant, не assistant
- ✕ Не писать в `attributes` на этом этапе — семантика появляется в задаче 13
- ✕ Не добавлять `contact_groups` — откладывается до задачи перед 18
- ✕ Не использовать `ChannelTypeEnum` для поля `platform` — это разные концепты
- ✕ Не ставить FK `tenant_id → tenants` в tenant-схеме — Migration Isolation Contract

---

## Тесты (PHPUnit)

`tests/Unit/Domains/Contact/`

```
ContactServiceTest
  ✓ test_find_or_create_creates_new_contact
  ✓ test_find_or_create_returns_existing_contact
  ✓ test_find_or_create_unique_per_tenant_platform_external_id
  ✓ test_same_external_id_different_platform_creates_separate_contacts
  ✓ test_same_external_id_different_tenant_creates_separate_contacts

ChannelContactServiceTest
  ✓ test_find_or_create_channel_contact_creates_new
  ✓ test_find_or_create_channel_contact_updates_last_interaction_at
  ✓ test_find_or_create_channel_contact_idempotent
```

---

## Зависимости

- Задача 04 — TenantContext, DB routing
- Задача 06b — `channels` таблица (FK в `channel_contacts`)
- Задача 06d — `HasUlidPrimaryKey` trait (ID Strategy)
- Блокирует: задача 08 (Webhook routing)