# ADR-02 — Assistant Panel: separate operational console

> Зафиксировано: март 2026. Статус: **принято**.
> 

---

## Решение

Assistant panel реализована как **отдельный Filament panel provider**.

Assistant — не только CRUD-сущность. После создания ассистент становится независимым операционным контекстом внутри tenant.

---

## Разделение UI

| Panel | Prefix | Ответственность |
| --- | --- | --- |
| **Main Admin Panel** | `/admin` | Users, Assistants list, Roles, Permissions, Tenant-level settings, Triggers, Mailings |
| **Assistant Panel** | `/assistant` | Channels, Flows, Prompts, Settings, Sessions, Logs конкретного ассистента |

---

## Причина

Управление ассистентом внутри `AssistantResource` приводит к:

- Перегруженному resource
- Смешиванию platform administration и assistant runtime
- Слабой границе между платформой и assistant lifecycle

---

## Модель panel

### Префикс

Статичный prefix `/assistant`. Динамический prefix вида `/assistant/{assistant}` намеренно не используется:

- сложность Filament routing lifecycle
- время panel boot
- риски Octane safety (ADR-01)

### Идентификация assistant

Assistant identity передаётся через query parameter:

```
/assistant?assistant={uuid}
```

**Session допускается только как fallback.**

Session не является первичным источником идентичности потому что создаёт:

- конфликты multi-tab
- скрытое переключение контекста
- недетерминированную навигацию

### Резолвинг assistant

Через `ResolveAssistantMiddleware`:

1. Читает `?assistant=uuid` (приоритет) или `assistant.current_id` из сессии
2. Авторизует доступ
3. Резолвит assistant
4. Записывает в `CurrentAssistant`

### Runtime context

`CurrentAssistant` — scoped binding, по аналогии с `TenantContext`.

```php
app(CurrentAssistantInterface::class)->get()
```

---

## Authorization

- admin → полный доступ ко всем ассистентам
- обычный user → только назначенные (`user_assistants` pivot)

Те же правила что в `AssistantResource`. Дублирования логики нет.

---

## Навигация

Assistant panel имеет собственное дерево навигации, независимое от main admin panel.

Минимальная начальная структура:

- Overview
- Channels
- Flow (placeholder)
- Settings (placeholder)

---

## Правило бизнес-логики

Assistant panel не дублирует доменную логику. Все действия только через:

- `AssistantService`
- `ChannelService`

---

## Future compatibility

Решение обеспечивает расширение без перепроектирования:

- Flow editor
- Runtime sessions
- Assistant logs
- Webhook diagnostics

---

## Связанные документы

- **ADR-01 — Octane: ingress-only** — почему статичный prefix и не Octane
- **Задача 06.2 — Assistant Panel (Filament)** — реализация

---

## Связано с

- [[04-assistant-domain]] — доменная модель Assistant
- [[06-flow-engine]] — Flow Engine как основной компонент ассистента
- [[03-staff-users]] — Staff пользователи и доступ к ассистентам