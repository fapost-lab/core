# Multilingual support (i18n)

> Архив Notion. Актуальная документация: [[15-multilingual]]


Depends on: 07 (Contact), 10 (Flow Session), 20 (Filament UI), 27 (Flow constructor)
Domain: Flow
Phase: 5 — Self-hosted & SaaS
Sprint: 11
Status: Готово
Task №: 28

## Цель

Внедрить мультиязычную инфраструктуру платформы: runtime language resolution, system content translations, flow multilingual content и Flow Content Manager.

**Архитектурная страница:** [15 — Мультиязычность](../%D0%90%D1%80%D1%85%D0%B8%D1%82%D0%B5%D0%BA%D1%82%D1%83%D1%80%D0%B0%20%D0%BF%D0%BB%D0%B0%D1%82%D1%84%D0%BE%D1%80%D0%BC%D1%8B/ADR-15%20%E2%80%94%20%D0%9C%D1%83%D0%BB%D1%8C%D1%82%D0%B8%D1%8F%D0%B7%D1%8B%D1%87%D0%BD%D0%BE%D1%81%D1%82%D1%8C%203456e2329a6e8121b68cf006d231bdad.md)

---

## Зафиксированные архитектурные решения

**contacts.language ownership** — единственный платформенный способ установить язык контакта — нода `set_attribute`. Прямая запись из других мест запрещена.

**Где резолвится перевод** — отдельный слой `ContentTranslator` (не sender, не handler). Вызывается из flow engine перед постановкой сообщения в очередь, и из `BroadcastSendJob` per-contact. `MessageSender` получает уже готовый финальный текст. Системные сообщения (fallback, error messages) также проходят через этот слой.

**Fallback chain при отсутствии перевода** — если `(key, language)` не найден в `tenant_translations`, берётся значение на `content_base_language`. Если и оно отсутствует — возвращается ключ (не исключение). Ошибка логируется.

**`content_base_language` и `fallback_language`** — выставляются при создании тенанта (в `platform:install` / onboarding wizard). После создания `content_base_language` locked на уровне UI и API если `flow_definitions count > 0`.

**Кеш переводов** — Redis per `(tenant_id, language)`: при первом обращении грузится весь namespace языка одним запросом, кладётся в Redis. Инвалидируется при любом изменении перевода в данном языке для данного тенанта.

---

## Часть 1 — Инфраструктура (до или вместе с Task 20)

### Изменения схемы

**`contacts`** — добавить поле:

```sql
contacts.language VARCHAR(10) NULL
```

Canonical поле. Не в `attributes`. Участвует в runtime resolution. Пишется только через `set_attribute`-ноду.

**`assistants`** — добавить поле:

```sql
assistants.default_language VARCHAR(10) NOT NULL DEFAULT 'en'
```

**`tenant_settings`** — добавить поля:

```php
// в TenantSettings (spatie/laravel-settings)
public string $content_base_language = 'en';   // locked после создания flow definitions
public array $available_languages = ['en'];
public string $fallback_language = 'en';        // выставляется при создании тенанта
```

**Новая таблица `tenant_translations`:**

```sql
CREATE TABLE tenant_translations (
    id UUID PRIMARY KEY,
    tenant_id UUID NOT NULL,
    key VARCHAR(255) NOT NULL,
    language VARCHAR(10) NOT NULL,
    value TEXT NOT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    UNIQUE (tenant_id, key, language)
);
```

### LanguageResolver

```php
interface LanguageResolverInterface
{
    public function resolve(Contact $contact, Assistant $assistant, FlowSession $session): string;
}
```

**Цепочка (первый непустой побеждает):**

```
flow_sessions.state.system.language
?? contacts.language          -- устанавливается только set_attribute
?? assistants.default_language
?? tenant_settings.fallback_language
```

> Прямые проверки языка в handlers и sender pipeline запрещены — только через LanguageResolver.
> 

### ContentTranslator

Отдельный слой между flow engine / broadcast и MessageSender.

```php
interface ContentTranslatorInterface
{
    public function translate(string $key, string $language): string;
}
```

- Вызывается из flow engine (перед постановкой в transactional queue) и из `BroadcastSendJob` (per-contact, после `LanguageResolver::resolve()`)
- Использует Redis-кеш per `(tenant_id, language)` — весь namespace языка одним запросом при cache miss
- Инвалидация кеша при изменении любого перевода в данном `(tenant_id, language)`
- Fallback: `language` → `content_base_language` → вернуть ключ + залогировать

### Content lock

`content_base_language` locked на уровне UI: поле disabled если `flow_definitions count > 0` для данного tenant. Изменение через API без этой проверки — запрещено.

---

## Часть 2 — Flow Content Manager (вместе с Task 27)

Отдельная вкладка внутри конструктора flow. Extractor проходит по flow JSON и собирает локализуемый content.

**Stable key format:** `{node_id}.{field_path}`

Примеры: `node_12.text`, `node_12.buttons.0.label`, `node_20.invalid_message`

> Node UUID не меняется при перемещении ноды — stable keys остаются валидными.
> 

**Разрешено редактировать:** text, labels, captions, prompts.

**Запрещено:** outputs, next links, button values, button order/count, condition values, node structure.

**Canvas всегда показывает** `content_base_language`.

> ⚠ При удалении ноды все переводы для неё теряются — ожидаемое поведение.
> 

---

## Открытые вопросы

- Drag-and-drop перемещение нод с сохранением переводов — отложено до проработки flat flow builder (Task 27)
- Детали реализации flat flow builder (вместо Vue Flow) — будут описаны отдельно при подходе к Task 27