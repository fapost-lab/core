# ADR-15 — Мультиязычность

> **Superseded in part (2026-10).** What the code does differs from the text below:
>
> - `LanguageResolverInterface::resolve(Contact $contact, FlowSession $session)` takes no `Assistant`. The chain is
>   session (`state.system.language`) → `contact.language` → `TenantSettings::fallback_language`. There is **no assistant
>   level**: `assistants.default_language` exists as a column but the resolver does not read it
>   (`app/Domains/Flow/Services/LanguageResolver.php`).
> - Flow content fallback is: requested language → `content_base_language` → the first key present
>   (`CachedContentTranslator::resolveField`), not `assistant.default_language` → `fallback_language`.
> - System translations resolve through `CachedContentTranslator::translate`: `assistant_translations` →
>   `tenant_translations` (requested language, then `content_base_language`) → the core catalog → the key itself. The
>   assistant translation layer that the original text left open is built (`assistant_translations` table).
> - Admin UI language files live in `lang/` (not `resources/lang/`).
> - The handlers `language_selection` and `contact.update_language` do not exist.
> - The `content_base_language` lock is a save-time validation, not a UI-only rule: once any `flow_definitions` row (a
>   published flow) exists, the field is disabled and a save that changes it is rejected — in Filament's
>   `TenantSettingsPage`, and on the Inertia admin screen by `TenantSettingsRequest::after()`, with
>   `TenantSettingsEditor::save()` keeping the stored value whatever it is passed.

Апрель 2026 · Зафиксировано по результатам архитектурного ревью.

---

## Базовое разделение языковых слоёв

Платформа различает два независимых языковых слоя:

**Admin UI language** — framework layer. Источник: Laravel lang files (`resources/lang/{locale}`). Используется только для интерфейса панели управления, backend validation, staff уведомлений и системных элементов. Не связан с ботами, flow и tenant runtime.

**Content language** — runtime layer. Используется для ассистентов, flow, сообщений пользователям и системных bot-level сообщений.

---

## Tenant language policy

Tenant является владельцем content language policy. Хранится в `tenant_settings`:

- `content_base_language` — source language для всех flow tenant
- `available_languages` — список доступных языков
- `fallback_language` — последний fallback для content resolver

**Ограничение:** `content_base_language` locked после создания первого flow. Enforcement реализован на уровне UI: поле disabled если `flow_definitions count > 0`. Изменение через UI запрещено.

---

## Runtime Language Resolution

Единый сервис `LanguageResolver` — единственная точка резолвинга языка. Прямые проверки языка по месту в handlers и sender pipeline запрещены.

**Контракт:**

```php
interface LanguageResolverInterface
{
    public function resolve(Contact $contact, Assistant $assistant, FlowSession $session): string;
}
```

**Цепочка резолвинга (первый непустой побеждает):**

```jsx
effective_language =
    session.state.system.language
    ?? contact.language
    ?? assistant.default_language
    ?? tenant_settings.fallback_language
```

### Уровни хранения

**1. Session language** — `flow_sessions.state.system.language`

Временный override языка внутри конкретной сессии. Используется когда flow меняет язык (например, пользователь выбрал язык в начале сценария). Живёт только пока живёт сессия. Пишут: `language_selection` handler и специальные flow handlers.

**2. Contact language** — `contacts.language` (отдельное canonical поле)

Не в `contacts.attributes` — это canonical data, часто читается, участвует в runtime, используется многими flow. Основной язык пользователя для всех новых сессий. Пишет: handler `contact.update_language`.

**3. Assistant default language** — `assistants.default_language`

Язык по умолчанию для новых пользователей у которых ещё нет `contact.language`. Используется если session и contact language отсутствуют.

**4. Tenant fallback language** — `tenant_settings.fallback_language`

Последний fallback для content resolver. Используется только если ни один из вышестоящих уровней не дал результата.

> ⚠ `content_base_language` не входит в runtime resolution chain. Он нужен только для source language flow и content manager. Runtime fallback идёт через `tenant_settings.fallback_language`.
> 

---

## System content translations

System content принадлежит tenant уровню: auth messages, validation messages, fallback replies, default assistant service buttons.

**Хранение:** таблица `tenant_translations`

| Поле | Описание |
| --- | --- |
| `tenant_id` | Владелец |
| `key` | Ключ (например `buttons.yes`, `auth.enter_phone`) |
| `language` | Язык |
| `value` | Значение |

**Fallback chain для system content:**

```
tenant translation → core translation → core fallback language
```

> Когда core defaults обновляются (новые ключи), tenant видит их через lazy fallback при runtime — без миграции данных.
> 

---

## Flow multilingual content

Flow хранит только user-authored content. Локализуемые поля: message text, custom button labels, captions, prompts, service visible labels.

**Формат хранения в flow JSON:**

```json
{
  "text": {
    "es": "Bienvenido",
    "en": "Welcome"
  },
  "buttons": [
    {
      "label": { "es": "Confirmar", "en": "Confirm" },
      "value": "confirm"
    }
  ]
}
```

> `value` никогда не переводится — только `label`.
> 

**Default generated buttons** (yes/no, confirm/cancel, back) не являются flow translations — они переводятся через `tenant_translations`.

**Fallback chain для flow content:**

```
localized field → assistant.default_language → tenant.fallback_language
```

---

## Flow Content Manager (Flow Builder)

Отдельная вкладка внутри конструктора flow — не translation page, а extracted content manager.

**Принцип:** Flow Builder отвечает за структуру, связи, логику и node configuration. Flow Content Manager — за локализованный контент.

**Canvas всегда показывает `content_base_language`.** Base language редактируется тоже через Flow Content Manager — без входа внутрь node editor.

**Stable key format:** `node_id + field_path`

Примеры: `node_12.text`, `node_12.buttons.0.label`, `node_20.invalid_message`

> ⚠ При удалении ноды все переводы для этой ноды теряются — ожидаемое поведение.
> 

**Табличный вид:**

| key | base (es) | en | de |
| --- | --- | --- | --- |
| node_12.text | Bienvenido | Welcome |  |
| node_12.buttons.0.label | Confirmar | Confirm |  |

**Разрешено редактировать:** text, labels, captions, prompts.

**Запрещено:** outputs, next links, button order, button count, button values, condition values, node structure.

---

## Открытые вопросы

- Drag-and-drop перемещение нод в конструкторе для сохранения переводов при реорганизации flow — отложено до проработки flat flow builder.
- Детали реализации flat flow builder (вместо Vue Flow) — будут описаны отдельно при подходе к Task 27.

---

## Архитектурный итог

| Слой | Хранение | Владелец |
| --- | --- | --- |
| Admin UI language | Laravel lang files | Framework |
| Tenant language policy | `tenant_settings` | Tenant |
| Assistant default language | `assistants.default_language` | Assistant |
| Contact language | `contacts.language` | Contact |
| Session language | `flow_sessions.state.system.language` | FlowEngine |
| Flow content | inline JSON в nodes | Flow author |
| System translations | `tenant_translations` | Tenant + Core |

---

## Связано с

- [[02-input]] — input нода захватывает язык контакта
- [[01-send-message]] — отправка локализованного контента
- [[06-flow-engine]] — flow engine и языковой резолвинг
- [[README]] — обзор flow engine спеки
- [[05-contacts]] — Contact Domain (contacts.language canonical поле)
- [[04-assistant-domain]] — Assistant default_language
- [[specs/flow-engine/README]] — flow engine спека